<?php

namespace App\Services\Performance;

final class ScoreResult
{
    public function __construct(
        public readonly int $score,
        public readonly string $tier,
        public readonly array $breakdown,
    ) {}
}
