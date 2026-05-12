<?php

namespace App\Books\Services;

use App\Models\Book\Asset;
use App\Models\Book\Entry;
use App\Models\Book\EntryPayment;
use App\Models\Book\FiscalYear;
use App\Models\Book\IncomeEntry;

class FiscalYearAggregator
{
    /**
     * Sections whose inbound (direction=in) payments count as Cash Received.
     * Salary/Expense/Rent/Assets inflows are almost always data-entry quirks
     * and are excluded so the KPI reflects real receipts + loan settlements.
     */
    public const CASH_RECEIVED_SECTION_SLUGS = ['loan', 'loans_taken', 'receipts'];

    public function __construct(
        private ?DepreciationCalculator $dep = null,
    ) {
        $this->dep ??= new DepreciationCalculator();
    }

    public function totalIncome(FiscalYear $fy): float
    {
        return (float) IncomeEntry::where('fiscal_year_id', $fy->id)->sum('amount');
    }

    public function cashOutflow(FiscalYear $fy): float
    {
        return (float) EntryPayment::whereHas('entry',
                fn ($q) => $q->where('fiscal_year_id', $fy->id))
            ->where('direction', 'out')->sum('amount');
    }

    public function cashInflowFromRecoveries(FiscalYear $fy): float
    {
        return (float) EntryPayment::whereHas('entry',
                fn ($q) => $q->where('fiscal_year_id', $fy->id)
                    ->whereHas('section', fn ($s) => $s->whereIn('slug', self::CASH_RECEIVED_SECTION_SLUGS)))
            ->where('direction', 'in')->sum('amount');
    }

    public function nonCashOutflow(FiscalYear $fy): float
    {
        $total = 0.0;
        $entries = Entry::where('fiscal_year_id', $fy->id)
            ->whereHas('section', fn ($q) => $q->where('kind', 'asset'))
            ->with('section')->get();
        foreach ($entries as $entry) {
            $asset = Asset::where('entry_id', $entry->id)->first();
            if (! $asset) {
                continue;
            }
            $total += (float) $this->dep->yearlyDepFor($asset, $fy);
        }

        return $total;
    }

    public function totalOutflow(FiscalYear $fy): float
    {
        return $this->cashOutflow($fy) + $this->nonCashOutflow($fy);
    }

    public function netPl(FiscalYear $fy): float
    {
        return $this->totalIncome($fy) - $this->totalOutflow($fy);
    }

    public function carryover(FiscalYear $fy): array
    {
        $prior = $this->priorFy($fy);
        if (! $prior) {
            return ['value' => 0.0, 'estimate' => false];
        }
        if ($prior->is_closed && $prior->closing_summary) {
            return [
                'value' => (float) ($prior->closing_summary['net_pl'] ?? 0),
                'estimate' => false,
            ];
        }

        return ['value' => $this->netPl($prior), 'estimate' => true];
    }

    public function priorFy(FiscalYear $fy): ?FiscalYear
    {
        return FiscalYear::where('company_id', $fy->company_id)
            ->where('end_date', '<', $fy->start_date)
            ->orderByDesc('end_date')->first();
    }

    /**
     * Returns 12 month buckets (FY start month → +11) of an absolute-cumulative
     * series for one of: total_income, cash_outflow, cash_received, salary_paid,
     * non_cash_outflow, total_outflow, net_pl. Each bucket is the running total
     * up to and including that month — produces a monotonic sparkline shape for
     * "growth over the year" metrics. For metrics that can swing both ways (net_pl)
     * each bucket is income_so_far − outflow_so_far.
     *
     * @return array<int, float>
     */
    public function monthlySeries(FiscalYear $fy, string $metric): array
    {
        $buckets = [];
        $start = $fy->start_date instanceof \Carbon\Carbon
            ? $fy->start_date->copy()
            : \Carbon\Carbon::parse($fy->start_date);
        $end = $fy->end_date instanceof \Carbon\Carbon
            ? $fy->end_date->copy()
            : \Carbon\Carbon::parse($fy->end_date);

        $income = $this->seriesByMonth(
            IncomeEntry::where('fiscal_year_id', $fy->id),
            'occurred_on', 'amount'
        );
        $outflowPayments = $this->seriesByMonth(
            EntryPayment::whereHas('entry', fn ($q) => $q->where('fiscal_year_id', $fy->id))
                ->where('direction', 'out'),
            'occurred_on', 'amount'
        );
        $recoveryPayments = $this->seriesByMonth(
            EntryPayment::whereHas('entry',
                fn ($q) => $q->where('fiscal_year_id', $fy->id)
                    ->whereHas('section', fn ($s) => $s->whereIn('slug', self::CASH_RECEIVED_SECTION_SLUGS)))
                ->where('direction', 'in'),
            'occurred_on', 'amount'
        );
        $salaryPayments = $this->seriesByMonth(
            EntryPayment::whereHas('entry',
                fn ($q) => $q->where('fiscal_year_id', $fy->id)
                    ->whereHas('section', fn ($s) => $s->where('slug', 'salary')))
                ->where('direction', 'out'),
            'occurred_on', 'amount'
        );

        $depAnnual = $this->nonCashOutflow($fy);
        $monthsInFy = max(1, $start->copy()->startOfMonth()->diffInMonths($end->copy()->startOfMonth()) + 1);
        $depPerMonth = $depAnnual / $monthsInFy;

        $cursor = $start->copy()->startOfMonth();
        $cumIncome = 0.0;
        $cumOutPay = 0.0;
        $cumRecov = 0.0;
        $cumSalary = 0.0;
        $cumDep = 0.0;
        for ($i = 0; $i < 12 && $cursor->lessThanOrEqualTo($end); $i++) {
            $key = $cursor->format('Y-m');
            $cumIncome  += (float) ($income[$key] ?? 0);
            $cumOutPay  += (float) ($outflowPayments[$key] ?? 0);
            $cumRecov   += (float) ($recoveryPayments[$key] ?? 0);
            $cumSalary  += (float) ($salaryPayments[$key] ?? 0);
            $cumDep     += $depPerMonth;

            $buckets[] = match ($metric) {
                'total_income'      => $cumIncome,
                'cash_outflow'      => $cumOutPay,
                'cash_received'     => $cumRecov,
                'salary_paid'       => $cumSalary,
                'non_cash_outflow'  => $cumDep,
                'total_outflow'     => $cumOutPay + $cumDep,
                'net_pl'            => $cumIncome - $cumOutPay - $cumDep,
                default             => 0.0,
            };

            $cursor->addMonth();
        }

        return $buckets;
    }

    /**
     * @return array<string, float>  keyed YYYY-MM
     */
    private function seriesByMonth(\Illuminate\Database\Eloquent\Builder $base, string $dateCol, string $sumCol): array
    {
        $driver = $base->getModel()->getConnection()->getDriverName();
        $expr = $driver === 'sqlite'
            ? "strftime('%Y-%m', {$dateCol})"
            : "DATE_FORMAT({$dateCol}, '%Y-%m')";

        return $base->selectRaw("{$expr} AS ym, SUM({$sumCol}) AS total")
            ->groupBy('ym')
            ->pluck('total', 'ym')
            ->map(fn ($v) => (float) $v)
            ->all();
    }

    public function priorYearKpis(FiscalYear $fy): ?array
    {
        $prior = $this->priorFy($fy);
        if (! $prior) {
            return null;
        }

        return [
            'total_income'     => $this->totalIncome($prior),
            'cash_received'    => $this->cashInflowFromRecoveries($prior),
            'cash_outflow'     => $this->cashOutflow($prior),
            'non_cash_outflow' => $this->nonCashOutflow($prior),
            'total_outflow'    => $this->totalOutflow($prior),
            'net_pl'           => $this->netPl($prior),
            'label'            => $prior->label,
        ];
    }
}
