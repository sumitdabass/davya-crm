<?php
namespace Tests\Feature\Ai;

use App\Livewire\AiAssistantDrawer;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\User;
use App\Services\Ai\LlmProvider;
use App\Services\Ai\LlmResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AiAssistantDrawerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->app->bind(LlmProvider::class, fn() => new class implements LlmProvider {
            public function chat(array $m, array $t = []): LlmResponse
            {
                return new LlmResponse('drawer answer', null, 1, 1, 1, 'stub');
            }
        });
    }

    public function test_ask_appends_messages_to_thread(): void
    {
        $u = User::where('email','sumit@davya.local')->firstOrFail();
        $u->assignRole('admin');

        Livewire::actingAs($u)
            ->test(AiAssistantDrawer::class)
            ->set('input', 'What are BBA fees?')
            ->call('ask')
            ->assertSet('input', '')
            ->assertSee('drawer answer');

        $this->assertSame(1, AiConversation::count());
        $this->assertSame(2, AiMessage::count());
    }

    public function test_new_chat_resets_conversation(): void
    {
        $u = User::where('email','sumit@davya.local')->firstOrFail();
        $u->assignRole('admin');

        $component = Livewire::actingAs($u)
            ->test(AiAssistantDrawer::class)
            ->set('input', 'q1')->call('ask')
            ->call('newChat')
            ->assertSet('conversationId', null)
            ->assertSet('thread', []);

        $this->assertSame(1, AiConversation::count(), 'first conversation persists');
    }
}
