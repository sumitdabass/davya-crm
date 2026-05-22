<?php
namespace Tests\Unit\Ai;

use App\Services\Ai\LlmResponse;
use PHPUnit\Framework\TestCase;

class LlmResponseTest extends TestCase
{
    public function test_dto_construction(): void
    {
        $r = new LlmResponse(
            content: 'hello',
            toolCalls: null,
            tokenInput: 100,
            tokenOutput: 50,
            latencyMs: 1234,
            model: 'llama-3.3-70b-versatile',
        );

        $this->assertSame('hello', $r->content);
        $this->assertNull($r->toolCalls);
        $this->assertFalse($r->wantsTools());
    }

    public function test_wants_tools_when_tool_calls_present(): void
    {
        $r = new LlmResponse(
            content: '',
            toolCalls: [['id' => 'call_1', 'name' => 'search_pages', 'arguments' => ['query' => 'foo']]],
            tokenInput: 100, tokenOutput: 20, latencyMs: 800, model: 'x',
        );
        $this->assertTrue($r->wantsTools());
    }
}
