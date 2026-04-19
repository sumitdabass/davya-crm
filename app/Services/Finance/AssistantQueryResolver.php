<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Models\Expense;
use App\Models\Investment;
use App\Models\LedgerEntry;
use App\Models\Payment;
use App\Models\Student;

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
        $query = LedgerEntry::query();

        if (isset($filter['account'])) {
            $query->where('account', $filter['account']);
        }

        $entries = $query->orderByDesc('created_at')->limit($this->rowCap)->get();

        return [
            'summary' => [
                'balance'     => (float) $entries->sum('delta_amount'),
                'entry_count' => $entries->count(),
                'account'     => $filter['account'] ?? null,
            ],
            'rows' => $entries->map(fn ($e) => [
                'id'           => $e->id,
                'account'      => $e->account,
                'delta_amount' => $e->delta_amount,
                'source_type'  => $e->source_type,
                'source_id'    => $e->source_id,
                'note'         => $e->note,
                'created_at'   => $e->created_at?->toDateTimeString(),
            ])->toArray(),
        ];
    }

    private function recentCaptures(?array $timeRange): array
    {
        $from = $timeRange['from'] ?? now()->subDays(7)->toDateString();
        $to   = $timeRange['to']   ?? now()->toDateString();

        $payments = Payment::query()
            ->whereBetween('received_at', [$from, $to.' 23:59:59'])
            ->orderByDesc('received_at')
            ->limit($this->rowCap)
            ->get()
            ->map(fn ($p) => [
                'kind'   => 'payment',
                'at'     => $p->received_at,
                'amount' => (float) $p->amount,
                'id'     => $p->id,
            ]);

        $expenses = Expense::query()
            ->whereBetween('paid_at', [$from, $to.' 23:59:59'])
            ->orderByDesc('paid_at')
            ->limit($this->rowCap)
            ->get()
            ->map(fn ($e) => [
                'kind'     => 'expense',
                'at'       => $e->paid_at,
                'amount'   => (float) $e->amount,
                'id'       => $e->id,
                'category' => $e->category,
            ]);

        $investments = Investment::query()
            ->whereBetween('transacted_at', [$from, $to.' 23:59:59'])
            ->orderByDesc('transacted_at')
            ->limit($this->rowCap)
            ->get()
            ->map(fn ($i) => [
                'kind'       => 'investment',
                'at'         => $i->transacted_at,
                'amount'     => (float) $i->amount,
                'id'         => $i->id,
                'asset_name' => $i->asset_name,
                'direction'  => $i->direction,
            ]);

        $combined = $payments->concat($expenses)->concat($investments)
            ->sortByDesc('at')
            ->values()
            ->take($this->rowCap)
            ->all();

        return [
            'summary' => [
                'count' => count($combined),
                'from'  => $from,
                'to'    => $to,
            ],
            'rows' => $combined,
        ];
    }

    private function totalsByRange(?array $timeRange, array $filter): array
    {
        $from = $timeRange['from'] ?? now()->subDays(30)->toDateString();
        $to   = $timeRange['to']   ?? now()->toDateString();

        return [
            'summary' => [
                'from'             => $from,
                'to'               => $to,
                'payment_total'    => (float) Payment::query()
                    ->whereBetween('received_at', [$from, $to.' 23:59:59'])
                    ->sum('amount'),
                'expense_total'    => (float) Expense::query()
                    ->whereBetween('paid_at', [$from, $to.' 23:59:59'])
                    ->sum('amount'),
                'investment_total' => (float) Investment::query()
                    ->whereBetween('transacted_at', [$from, $to.' 23:59:59'])
                    ->sum('amount'),
            ],
            'rows' => [],
        ];
    }

    private function studentStatus(array $filter): array
    {
        $student = Student::query()
            ->with([
                'payments' => fn ($q) => $q->select('id', 'student_id', 'amount', 'received_at', 'type')->limit($this->rowCap),
                'roundHistory' => fn ($q) => $q->select('id', 'student_id', 'round_name', 'outcome', 'allotted_college', 'created_at')->limit($this->rowCap),
            ])
            ->when(isset($filter['student_phone']), fn ($q) => $q->where('phone', $filter['student_phone']))
            ->when(isset($filter['student_name']),  fn ($q) => $q->where('name', 'like', '%'.$filter['student_name'].'%'))
            ->first();

        if (!$student) {
            return ['summary' => ['found' => false], 'rows' => []];
        }

        return [
            'summary' => [
                'found'         => true,
                'id'            => $student->id,
                'name'          => $student->name,
                'phone'         => $student->phone,
                'stage'         => $student->stage,
                'payment_total' => (float) $student->payments->sum('amount'),
            ],
            'rows' => [
                'payments' => $student->payments->take($this->rowCap)->toArray(),
                'rounds'   => $student->roundHistory->take($this->rowCap)->toArray(),
            ],
        ];
    }

    private function freeform(?array $timeRange): array
    {
        // Freeform falls back to recent captures but with a broader 30-day default
        // (vs. recent_captures' 7-day default) when no explicit time range is given.
        $timeRange ??= [
            'from' => now()->subDays(30)->toDateString(),
            'to'   => now()->toDateString(),
        ];

        return $this->recentCaptures($timeRange);
    }
}
