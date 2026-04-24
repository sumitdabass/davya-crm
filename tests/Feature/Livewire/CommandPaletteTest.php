<?php

namespace Tests\Feature\Livewire;

use App\Livewire\CommandPalette;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CommandPaletteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function unblock(User $user): User
    {
        $user->must_change_password = false;
        $user->save();
        return $user;
    }

    public function test_search_returns_matching_students(): void
    {
        $admin = $this->unblock(User::where('email', 'sumit@davya.local')->first());
        Student::create(['name' => 'Chaitanya Rao', 'phone' => '9999900001', 'owner_id' => $admin->id, 'lead_source' => 'Direct']);
        Student::create(['name' => 'Khushbu Sharma', 'phone' => '9999900002', 'owner_id' => $admin->id, 'lead_source' => 'Direct']);

        Livewire::actingAs($admin)
            ->test(CommandPalette::class)
            ->set('query', 'chait')
            ->assertSee('Chaitanya Rao')
            ->assertDontSee('Khushbu Sharma');
    }

    public function test_search_respects_visible_to_scope(): void
    {
        // Two heads in different teams. Their students should be isolated.
        $head1 = User::factory()->create();
        Role::firstOrCreate(['name' => 'head', 'guard_name' => 'web']);
        $head1->assignRole('head');
        $this->unblock($head1);

        $head2 = User::factory()->create();
        $head2->assignRole('head');
        $this->unblock($head2);

        Student::create(['name' => 'Across Team', 'phone' => '9999900003', 'owner_id' => $head1->id, 'lead_source' => 'Direct']);

        Livewire::actingAs($head2)
            ->test(CommandPalette::class)
            ->set('query', 'Across')
            ->assertDontSee('Across Team');
    }

    public function test_static_pages_always_visible_when_query_empty(): void
    {
        $admin = $this->unblock(User::where('email', 'sumit@davya.local')->first());

        Livewire::actingAs($admin)
            ->test(CommandPalette::class)
            ->set('query', '')
            ->assertSee('Pipeline')
            ->assertSee('Today')
            ->assertSee('Dashboard');
    }

    public function test_query_filters_pages(): void
    {
        $admin = $this->unblock(User::where('email', 'sumit@davya.local')->first());

        Livewire::actingAs($admin)
            ->test(CommandPalette::class)
            ->set('query', 'field')
            ->assertSee('Fields')
            ->assertDontSee('Dashboard');
    }
}
