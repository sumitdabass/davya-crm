<?php

namespace App\Services\Performance;

final class TeamMaxes
{
    public function __construct(
        public readonly int $closedWon,
        public readonly int $dealWonAmount,
        public readonly int $advanceReceived,
    ) {}

    public static function empty(): self
    {
        return new self(0, 0, 0);
    }

    public function toArray(): array
    {
        return [
            'closed_won'       => $this->closedWon,
            'deal_won_amount'  => $this->dealWonAmount,
            'advance_received' => $this->advanceReceived,
        ];
    }
}
