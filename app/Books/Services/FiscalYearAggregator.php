<?php

namespace App\Books\Services;

use App\Models\Book\Asset;
use App\Models\Book\Entry;
use App\Models\Book\EntryPayment;
use App\Models\Book\FiscalYear;
use App\Models\Book\IncomeEntry;

class FiscalYearAggregator
{
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
                fn ($q) => $q->where('fiscal_year_id', $fy->id))
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
        return $this->totalIncome($fy)
             + $this->cashInflowFromRecoveries($fy)
             - $this->totalOutflow($fy);
    }

    public function carryover(FiscalYear $fy): array
    {
        $prior = FiscalYear::where('company_id', $fy->company_id)
            ->where('end_date', '<', $fy->start_date)
            ->orderByDesc('end_date')->first();
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
}
