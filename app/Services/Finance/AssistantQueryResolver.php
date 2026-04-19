<?php

declare(strict_types=1);

namespace App\Services\Finance;

class AssistantQueryResolver
{
    public function __construct(
        private readonly int $rowCap = 200,
    ) {}

    public function resolve(string $intent, ?array $timeRange, ?array $filter): array
    {
        return match ($intent) {
            'payments_by_student' => $this->paymentsByStudent($filter ?? []),
            'spend_by_category'   => $this->spendByCategory($filter ?? [], $timeRange),
            'ledger_balance'      => $this->ledgerBalance($filter ?? []),
            'recent_captures'     => $this->recentCaptures($timeRange),
            'totals_by_range'     => $this->totalsByRange($timeRange, $filter ?? []),
            'student_status'      => $this->studentStatus($filter ?? []),
            'freeform'            => $this->freeform($timeRange),
            default               => $this->freeform($timeRange),
        };
    }

    private function paymentsByStudent(array $filter): array
    {
        return ['summary' => [], 'rows' => []];
    }

    private function spendByCategory(array $filter, ?array $timeRange): array
    {
        return ['summary' => [], 'rows' => []];
    }

    private function ledgerBalance(array $filter): array
    {
        return ['summary' => [], 'rows' => []];
    }

    private function recentCaptures(?array $timeRange): array
    {
        return ['summary' => [], 'rows' => []];
    }

    private function totalsByRange(?array $timeRange, array $filter): array
    {
        return ['summary' => [], 'rows' => []];
    }

    private function studentStatus(array $filter): array
    {
        return ['summary' => [], 'rows' => []];
    }

    private function freeform(?array $timeRange): array
    {
        return ['summary' => [], 'rows' => []];
    }
}
