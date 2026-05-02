<?php

namespace App\Services\Performance;

final class SignalSet
{
    public function __construct(
        public readonly int $closedWon,
        public readonly int $dealWonAmount,
        public readonly int $rankProbAvg,
        public readonly int $advanceReceived,
        public readonly int $casesCaptured,
        public readonly int $meetingsHeld,
        public readonly int $openLeads,
        public readonly int $balanceAmount,
        public readonly int $staleOpen,
    ) {}

    public static function empty(): self
    {
        return new self(0, 0, 0, 0, 0, 0, 0, 0, 0);
    }

    public function toArray(): array
    {
        return [
            'closed_won' => $this->closedWon,
            'deal_won_amount' => $this->dealWonAmount,
            'rank_prob_avg' => $this->rankProbAvg,
            'advance_received' => $this->advanceReceived,
            'cases_captured' => $this->casesCaptured,
            'meetings_held' => $this->meetingsHeld,
            'open_leads' => $this->openLeads,
            'balance_amount' => $this->balanceAmount,
            'stale_open' => $this->staleOpen,
        ];
    }
}
