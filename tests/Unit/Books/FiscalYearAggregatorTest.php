<?php

namespace Tests\Unit\Books;

use App\Books\Services\FiscalYearAggregator;
use App\Models\Book\Company;
use App\Models\Book\Entry;
use App\Models\Book\EntryPayment;
use App\Models\Book\FiscalYear;
use App\Models\Book\IncomeEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FiscalYearAggregatorTest extends TestCase
{
    use RefreshDatabase;

    private function makeFyWithRows(): array
    {
        $c = Company::factory()->create();
        $fy = FiscalYear::factory()->create([
            'company_id' => $c->id,
            'start_date' => '2025-04-01',
            'end_date' => '2026-03-31',
            'label' => '2025-26',
        ]);
        // The salary section is auto-seeded on every Company by the
        // CompanyObserver — reuse it instead of creating a duplicate.
        $s = $c->sections()->where('slug', 'salary')->firstOrFail();
        IncomeEntry::create([
            'company_id' => $c->id,
            'fiscal_year_id' => $fy->id,
            'occurred_on' => '2025-04-15',
            'source' => 'A',
            'amount' => 12500000,
        ]);
        $e = Entry::create([
            'company_id' => $c->id,
            'fiscal_year_id' => $fy->id,
            'section_id' => $s->id,
            'title' => 'Usha',
            'salary_amount' => 1200000,
        ]);
        EntryPayment::create([
            'entry_id' => $e->id,
            'occurred_on' => '2025-05-01',
            'amount' => 200000,
            'direction' => 'out',
            'mode' => 'bank',
        ]);

        return [$c, $fy];
    }

    public function test_sums_total_income_across_the_fy(): void
    {
        [, $fy] = $this->makeFyWithRows();
        $agg = new FiscalYearAggregator();

        $this->assertSame(12500000.0, (float) $agg->totalIncome($fy));
    }

    public function test_sums_cash_outflow_from_payments_direction_out(): void
    {
        [, $fy] = $this->makeFyWithRows();

        $this->assertSame(200000.0, (float) (new FiscalYearAggregator())->cashOutflow($fy));
    }

    public function test_returns_zero_non_cash_outflow_when_no_asset_sections(): void
    {
        [, $fy] = $this->makeFyWithRows();

        $this->assertSame(0.0, (float) (new FiscalYearAggregator())->nonCashOutflow($fy));
    }

    public function test_computes_net_pl_income_plus_recoveries_minus_total_outflow(): void
    {
        [, $fy] = $this->makeFyWithRows();
        // 12500000 + 0 - (200000 + 0) = 12300000
        $this->assertSame(12300000.0, (float) (new FiscalYearAggregator())->netPl($fy));
    }
}
