<?php

namespace App\Books\Services;

use App\Models\Book\FiscalYear;

class ClosingSnapshotWriter
{
    public function __construct(
        private ?FiscalYearAggregator $agg = null,
    ) {
        $this->agg ??= new FiscalYearAggregator();
    }

    public function close(FiscalYear $fy): void
    {
        $fy->update([
            'is_closed' => true,
            'closing_summary_json' => [
                'total_income' => $this->agg->totalIncome($fy),
                'cash_outflow' => $this->agg->cashOutflow($fy),
                'non_cash_outflow' => $this->agg->nonCashOutflow($fy),
                'cash_inflow_from_recoveries' => $this->agg->cashInflowFromRecoveries($fy),
                'net_pl' => $this->agg->netPl($fy),
                'closed_at' => now()->toIso8601String(),
            ],
        ]);
    }

    public function reopen(FiscalYear $fy): void
    {
        $fy->update([
            'is_closed' => false,
            'closing_summary_json' => null,
        ]);
    }
}
