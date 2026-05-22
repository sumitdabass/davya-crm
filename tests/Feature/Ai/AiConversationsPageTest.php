<?php
namespace Tests\Feature\Ai;

use App\Filament\Pages\AiConversations;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AiConversationsPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_admin_sees_all_conversations(): void
    {
        $admin = User::where('email','sumit@davya.local')->firstOrFail();
        $admin->assignRole('admin');
        $other = User::factory()->create();

        AiConversation::create(['user_id' => $admin->id, 'title' => 'A', 'started_at' => now(), 'last_message_at' => now()]);
        AiConversation::create(['user_id' => $other->id, 'title' => 'B', 'started_at' => now(), 'last_message_at' => now()]);

        Livewire::actingAs($admin)
            ->test(AiConversations::class)
            ->assertSee('A')
            ->assertSee('B');
    }

    public function test_regular_user_sees_only_own(): void
    {
        $other = User::factory()->create();
        $other->givePermissionTo('use ai-agent');
        $admin = User::where('email','sumit@davya.local')->firstOrFail();
        $admin->assignRole('admin');

        AiConversation::create(['user_id' => $admin->id, 'title' => 'Admin one', 'started_at' => now(), 'last_message_at' => now()]);
        AiConversation::create(['user_id' => $other->id, 'title' => 'Other one', 'started_at' => now(), 'last_message_at' => now()]);

        Livewire::actingAs($other)
            ->test(AiConversations::class)
            ->assertSee('Other one')
            ->assertDontSee('Admin one');
    }

    public function test_no_permission_cannot_access(): void
    {
        $u = User::factory()->create();
        $this->assertFalse(AiConversations::canAccess() && auth()->loginUsingId($u->id) !== null);
        // Direct call:
        auth()->login($u);
        $this->assertFalse(AiConversations::canAccess());
    }
}
