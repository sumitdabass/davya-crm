<?php
namespace Tests\Feature\Ai;

use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\User;
use App\Services\Ai\LlmProvider;
use App\Services\Ai\LlmResponse;
use App\Services\Ai\Providers\GroqException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AiAssistantControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->app->bind(LlmProvider::class, fn() => new class implements LlmProvider {
            public function chat(array $m, array $t = []): LlmResponse
            {
                return new LlmResponse('canned answer', null, 10, 5, 100, 'stub');
            }
        });
    }

    private function adminWithPermission(): User
    {
        $u = User::where('email','sumit@davya.local')->firstOrFail();
        $u->assignRole('admin');
        return $u;
    }

    public function test_permission_denied_for_user_without_use_ai_agent(): void
    {
        $u = User::factory()->create();
        $this->actingAs($u)
            ->postJson('/ai/ask', ['question' => 'hi'])
            ->assertStatus(403);
    }

    public function test_creates_conversation_and_persists_messages(): void
    {
        $u = $this->adminWithPermission();
        $resp = $this->actingAs($u)->postJson('/ai/ask', ['question' => 'BBA fees?']);

        $resp->assertOk()
            ->assertJsonPath('answer', 'canned answer')
            ->assertJsonStructure(['conversation_id', 'answer']);

        $this->assertSame(1, AiConversation::where('user_id', $u->id)->count());
        $this->assertSame(2, AiMessage::count()); // user + assistant
    }

    public function test_continues_existing_conversation(): void
    {
        $u = $this->adminWithPermission();
        $conv = AiConversation::create(['user_id' => $u->id, 'started_at' => now(), 'last_message_at' => now()]);

        $this->actingAs($u)->postJson('/ai/ask', [
            'question' => 'follow up',
            'conversation_id' => $conv->id,
        ])->assertOk();

        $this->assertSame(1, AiConversation::count());
        $this->assertSame(2, $conv->messages()->count());
    }

    public function test_daily_cap_enforced(): void
    {
        config(['ai.daily_cap_per_user' => 2]);
        $u = $this->adminWithPermission();
        $this->actingAs($u)->postJson('/ai/ask', ['question' => 'q1'])->assertOk();
        $this->actingAs($u)->postJson('/ai/ask', ['question' => 'q2'])->assertOk();
        $this->actingAs($u)->postJson('/ai/ask', ['question' => 'q3'])->assertStatus(429);
    }

    public function test_groq_failure_does_not_count_against_cap(): void
    {
        config(['ai.daily_cap_per_user' => 2]);
        $this->app->bind(LlmProvider::class, fn() => new class implements LlmProvider {
            public function chat(array $m, array $t = []): LlmResponse {
                throw new GroqException('boom', 500);
            }
        });

        $u = $this->adminWithPermission();
        $this->actingAs($u)->postJson('/ai/ask', ['question' => 'q1'])->assertStatus(503);
        $this->actingAs($u)->postJson('/ai/ask', ['question' => 'q1'])->assertStatus(503);
        // still no user-role messages persisted? — only assistant errors aren't persisted.
        $this->assertSame(0, AiMessage::where('role','user')->count());
    }
}
