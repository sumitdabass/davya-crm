<?php
namespace Tests\Feature\Ai;

use App\Models\User;
use App\Services\Ai\LlmProvider;
use App\Services\Ai\LlmResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AiEndToEndTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $root = sys_get_temp_dir().'/e2e_'.uniqid();
        mkdir($root);
        file_put_contents("$root/bba-fees.php",
            "<?php ?><title>BBA Fees</title>\n<p>BBA tuition is Rs 95,000 per semester at VIPS-TC.</p>");
        config(['ai.ipu_docroot' => $root]);

        $this->app->bind(LlmProvider::class, fn() => new class implements LlmProvider {
            public int $call = 0;
            public function chat(array $m, array $t = []): LlmResponse
            {
                $this->call++;
                if ($this->call === 1) {
                    return new LlmResponse('', [['id'=>'c1','name'=>'search_pages','arguments'=>['query'=>'BBA']]], 10, 5, 50, 'stub');
                }
                if ($this->call === 2) {
                    return new LlmResponse('', [['id'=>'c2','name'=>'read_page','arguments'=>['slug'=>'bba-fees.php']]], 10, 5, 50, 'stub');
                }
                return new LlmResponse('BBA tuition is Rs 95,000.', null, 10, 5, 50, 'stub');
            }
        });
    }

    public function test_full_round_trip_via_http(): void
    {
        $u = User::where('email','sumit@davya.local')->firstOrFail();
        $u->assignRole('admin');

        $resp = $this->actingAs($u)->postJson('/ai/ask', ['question' => 'BBA fees at VIPS-TC?']);

        $resp->assertOk();
        $this->assertStringContainsString('Rs 95,000', $resp->json('answer'));
        $this->assertStringContainsString('Source: bba-fees.php', $resp->json('answer'));
        $this->assertSame(['bba-fees.php'], $resp->json('citations'));
    }
}
