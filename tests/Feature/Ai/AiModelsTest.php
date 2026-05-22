<?php
namespace Tests\Feature\Ai;

use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AiModelsTest extends TestCase
{
    use RefreshDatabase;

    public function test_conversation_has_messages_and_user(): void
    {
        $this->seed();
        $user = User::where('email','sumit@davya.local')->firstOrFail();
        $conv = AiConversation::factory()->for($user)->create();
        AiMessage::factory()->for($conv, 'conversation')->count(3)->create();

        $this->assertCount(3, $conv->refresh()->messages);
        $this->assertSame($user->id, $conv->user->id);
    }

    public function test_message_casts_json_columns(): void
    {
        $this->seed();
        $user = User::where('email','sumit@davya.local')->firstOrFail();
        $conv = AiConversation::factory()->for($user)->create();
        $msg = AiMessage::factory()->for($conv, 'conversation')->create([
            'role'       => 'assistant',
            'tool_calls' => [['name' => 'search_pages', 'args' => ['query' => 'foo']]],
            'citations'  => ['BBA.php', 'fees.php'],
        ]);

        $this->assertIsArray($msg->refresh()->tool_calls);
        $this->assertSame(['BBA.php','fees.php'], $msg->citations);
    }

    public function test_policy_admin_sees_all_user_sees_own(): void
    {
        $this->seed();
        $admin = User::where('email','sumit@davya.local')->firstOrFail();
        $admin->assignRole('admin');
        $other = User::factory()->create();
        $ownConv  = AiConversation::factory()->for($admin)->create();
        $theirConv = AiConversation::factory()->for($other)->create();

        $this->assertTrue($admin->can('view',  $ownConv));
        $this->assertTrue($admin->can('view',  $theirConv));
        $this->assertTrue($other->can('view',  $ownConv) === false);
        $this->assertTrue($other->can('view',  $theirConv));
    }
}
