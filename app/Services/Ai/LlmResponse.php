<?php
namespace App\Services\Ai;

final class LlmResponse
{
    public function __construct(
        public readonly string $content,
        public readonly ?array $toolCalls,
        public readonly int $tokenInput,
        public readonly int $tokenOutput,
        public readonly int $latencyMs,
        public readonly string $model,
    ) {}

    public function wantsTools(): bool
    {
        return is_array($this->toolCalls) && count($this->toolCalls) > 0;
    }
}
