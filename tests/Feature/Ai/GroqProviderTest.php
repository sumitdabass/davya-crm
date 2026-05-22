<?php
namespace Tests\Feature\Ai;

use App\Services\Ai\Providers\GroqProvider;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GroqProviderTest extends TestCase
{
    private function provider(): GroqProvider
    {
        return new GroqProvider(
            apiKey: 'test-key',
            model: 'llama-3.3-70b-versatile',
            baseUrl: 'https://api.groq.com/openai/v1',
            timeoutSeconds: 5,
        );
    }

    public function test_basic_text_response(): void
    {
        Http::fake([
            'api.groq.com/*' => Http::response([
                'choices' => [['message' => ['role' => 'assistant', 'content' => 'hello world']]],
                'usage'   => ['prompt_tokens' => 50, 'completion_tokens' => 10],
                'model'   => 'llama-3.3-70b-versatile',
            ], 200),
        ]);

        $resp = $this->provider()->chat([
            ['role' => 'user', 'content' => 'hi'],
        ]);

        $this->assertSame('hello world', $resp->content);
        $this->assertNull($resp->toolCalls);
        $this->assertSame(50, $resp->tokenInput);
        $this->assertSame(10, $resp->tokenOutput);
        $this->assertFalse($resp->wantsTools());
    }

    public function test_tool_call_response(): void
    {
        Http::fake([
            'api.groq.com/*' => Http::response([
                'choices' => [[
                    'message' => [
                        'role' => 'assistant',
                        'content' => null,
                        'tool_calls' => [[
                            'id' => 'call_abc',
                            'type' => 'function',
                            'function' => ['name' => 'search_pages', 'arguments' => '{"query":"BBA"}'],
                        ]],
                    ],
                ]],
                'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 20],
                'model' => 'llama-3.3-70b-versatile',
            ], 200),
        ]);

        $resp = $this->provider()->chat(
            [['role' => 'user', 'content' => 'BBA fees?']],
            [\App\Services\Ai\Tools\SearchPagesTool::definition()],
        );

        $this->assertTrue($resp->wantsTools());
        $this->assertSame('call_abc', $resp->toolCalls[0]['id']);
        $this->assertSame('search_pages', $resp->toolCalls[0]['name']);
        $this->assertSame(['query' => 'BBA'], $resp->toolCalls[0]['arguments']);
    }

    public function test_http_error_throws_runtime_exception(): void
    {
        Http::fake([
            'api.groq.com/*' => Http::response(['error' => 'rate limited'], 429),
        ]);

        $this->expectException(\App\Services\Ai\Providers\GroqException::class);
        $this->provider()->chat([['role' => 'user', 'content' => 'x']]);
    }
}
