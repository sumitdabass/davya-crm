<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Models\Expense;
use App\Models\Payment;

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
        $query = Payment::query()->with('student:id,name,phone');

        if (isset($filter['student_phone'])) {
            $query->whereHas('student', fn ($q) => $q->where('phone', $filter['student_phone']));
        }
        if (isset($filter['student_name'])) {
            $query->whereHas('student', fn ($q) => $q->where('name', 'like', '%'.$filter['student_name'].'%'));
        }

        $rows = $query->orderByDesc('received_at')->limit($this->rowCap)->get([
            'id', 'student_id', 'amount', 'type', 'mode', 'reference_number', 'received_at', 'notes',
        ])->toArray();

        return [
            'summary' => [
                'count'        => count($rows),
                'total_amount' => (float) array_sum(array_column($rows, 'amount')),
            ],
            'rows' => $rows,
        ];
    }

    private function spendByCategory(array $filter, ?array $timeRange): array
    {
        $from = $timeRange['from'] ?? now()->subDays(30)->toDateString();
        $to   = $timeRange['to']   ?? now()->toDateString();

        $query = Expense::query()
            ->whereBetween('paid_at', [$from, $to.' 23:59:59']);

        if (isset($filter['category'])) {
            $query->where('category', $filter['category']);
        }

        $rows = $query->orderByDesc('paid_at')->limit($this->rowCap)->get([
            'id', 'amount', 'category', 'description', 'paid_at',
        ])->toArray();

        return [
            'summary' => [
                'count'        => count($rows),
                'total_amount' => (float) array_sum(array_column($rows, 'amount')),
                'from'         => $from,
                'to'           => $to,
            ],
            'rows' => $rows,
        ];
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
