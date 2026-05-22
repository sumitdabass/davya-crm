<?php
namespace Tests\Feature\Ai;

use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\User;
use App\Services\Ai\AssistantService;
use App\Services\Ai\LlmProvider;
use App\Services\Ai\LlmResponse;
use App\Services\Ai\Tools\ReadPageTool;
use App\Services\Ai\Tools\SearchPagesTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AssistantServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function service(LlmProvider $provider, array $fsFiles = []): AssistantService
    {
        $root = sys_get_temp_dir().'/asvc_'.uniqid();
        mkdir($root);
        foreach ($fsFiles as $slug => $body) file_put_contents("$root/$slug", $body);

        return new AssistantService(
            provider: $provider,
            search:   new SearchPagesTool($root, []),
            read:     new ReadPageTool($root, 16384),
            maxRoundTrips: 3,
            historyTurns:  10,
        );
    }

    private function stubProvider(array $sequence): LlmProvider
    {
        return new class($sequence) implements LlmProvider {
            public function __construct(private array $seq) {}
            public function chat(array $m, array $t = []): LlmResponse
            {
                if ($this->seq === []) throw new \RuntimeException('stub exhausted');
                return array_shift($this->seq);
            }
        };
    }

    private function emptyConversation(): AiConversation
    {
        $user = User::where('email','sumit@davya.local')->firstOrFail();
        return AiConversation::create([
            'user_id'         => $user->id,
            'started_at'      => now(),
            'last_message_at' => now(),
        ]);
    }

    public function test_zero_round_trips_direct_answer(): void
    {
        $conv = $this->emptyConversation();
        $svc = $this->service($this->stubProvider([
            new LlmResponse('The BBA fee is 95k.', null, 100, 20, 500, 'm'),
        ]));

        $msg = $svc->ask($conv, 'BBA fees at VIPS-TC?');

        $this->assertSame('assistant', $msg->role);
        $this->assertStringContainsString('95k', $msg->content);
        $this->assertSame([], $msg->citations);
        // Trace: 1 user + 1 final assistant
        $this->assertSame(2, $conv->messages()->count());
    }

    public function test_search_then_read_then_answer(): void
    {
        $conv = $this->emptyConversation();
        $svc  = $this->service(
            $this->stubProvider([
                new LlmResponse('', [['id' => 'c1', 'name' => 'search_pages', 'arguments' => ['query' => 'BBA']]], 100, 10, 200, 'm'),
                new LlmResponse('', [['id' => 'c2', 'name' => 'read_page', 'arguments' => ['slug' => 'bba.php']]], 110, 10, 200, 'm'),
                new LlmResponse('BBA fees are 95k.', null, 200, 30, 400, 'm'),
            ]),
            ['bba.php' => "<?php ?><title>BBA</title>\nBBA fee is 95k per semester."],
        );

        $msg = $svc->ask($conv, 'BBA fees?');

        $this->assertStringContainsString('95k', $msg->content);
        $this->assertSame(['bba.php'], $msg->citations);
        $this->assertStringContainsString('Source: bba.php', $msg->content);
        // Trace: user + (asst+tool)*2 + final asst = 6
        $this->assertSame(6, $conv->messages()->count());
        $this->assertSame(2, $conv->messages()->where('role','tool')->count());
        $assistantToolMsgs = $conv->messages()->where('role','assistant')->whereNotNull('tool_calls')->count();
        $this->assertSame(2, $assistantToolMsgs);
    }

    public function test_round_trip_cap_falls_back_to_last_text(): void
    {
        $conv = $this->emptyConversation();
        $svc  = $this->service(
            $this->stubProvider([
                new LlmResponse('still thinking', [['id'=>'c1','name'=>'search_pages','arguments'=>['query'=>'x']]], 10, 10, 50, 'm'),
                new LlmResponse('still thinking', [['id'=>'c2','name'=>'search_pages','arguments'=>['query'=>'y']]], 10, 10, 50, 'm'),
                new LlmResponse('still thinking', [['id'=>'c3','name'=>'search_pages','arguments'=>['query'=>'z']]], 10, 10, 50, 'm'),
            ]),
        );

        $msg = $svc->ask($conv, 'x');
        $this->assertStringContainsString("couldn't pin down", $msg->content);
    }

    public function test_citations_dedupe(): void
    {
        $conv = $this->emptyConversation();
        $svc  = $this->service(
            $this->stubProvider([
                new LlmResponse('', [
                    ['id'=>'c1','name'=>'read_page','arguments'=>['slug'=>'bba.php']],
                    ['id'=>'c2','name'=>'read_page','arguments'=>['slug'=>'bba.php']],
                    ['id'=>'c3','name'=>'read_page','arguments'=>['slug'=>'fees.php']],
                ], 10, 10, 100, 'm'),
                new LlmResponse('done', null, 10, 10, 100, 'm'),
            ]),
            ['bba.php' => "<?php ?>BBA content", 'fees.php' => "<?php ?>Fee content"],
        );

        $msg = $svc->ask($conv, 'x');
        $this->assertSame(['bba.php','fees.php'], $msg->citations);
    }

    public function test_persists_token_and_latency_and_tool_calls(): void
    {
        $conv = $this->emptyConversation();
        $svc  = $this->service($this->stubProvider([
            new LlmResponse('answer', null, 123, 45, 678, 'llama-3.3-70b-versatile'),
        ]));

        $msg = $svc->ask($conv, 'x');
        $this->assertSame(123, $msg->token_input);
        $this->assertSame(45,  $msg->token_output);
        $this->assertSame(678, $msg->latency_ms);
        $this->assertSame('llama-3.3-70b-versatile', $msg->model);
    }
}
