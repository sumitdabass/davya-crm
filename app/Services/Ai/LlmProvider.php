<?php
namespace App\Services\Ai;

interface LlmProvider
{
    /**
     * @param array<int, array<string, mixed>> $messages Chat messages (OpenAI-style).
     * @param array<int, array<string, mixed>> $tools    Tool definitions (OpenAI-style).
     */
    public function chat(array $messages, array $tools = []): LlmResponse;
}
