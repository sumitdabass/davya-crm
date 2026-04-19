<?php

declare(strict_types=1);

namespace Tests\Unit\Finance;

use App\Services\Finance\GeminiClient;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class GeminiClientTest extends TestCase
{
    public function test_posts_to_gemini_and_returns_parsed_text(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [['content' => ['parts' => [['text' => 'Total spend: ₹8,200']]]]],
            ], 200),
        ]);

        $client = new GeminiClient(apiKey: 'test-key', model: 'gemini-2.5-flash');
        $reply = $client->generate(systemPrompt: 'you are finance bot', userJson: ['q' => 'x', 'rows' => []]);

        $this->assertSame('Total spend: ₹8,200', $reply);
    }

    public function test_throws_runtime_exception_on_non_200(): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['error' => 'quota'], 429)]);
        $client = new GeminiClient(apiKey: 'k', model: 'gemini-2.5-flash');
        $this->expectException(RuntimeException::class);
        $client->generate('sys', ['q' => 'x']);
    }

    public function test_throws_on_empty_candidates_text(): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response([
            'candidates' => [['content' => ['parts' => [['text' => '']]]]],
        ], 200)]);
        $client = new GeminiClient(apiKey: 'k', model: 'gemini-2.5-flash');
        $this->expectException(RuntimeException::class);
        $client->generate('sys', ['q' => 'x']);
    }
}
