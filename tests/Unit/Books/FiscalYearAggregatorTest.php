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

    public function test_computes_net_pl_as_income_minus_total_outflow(): void
    {
        [, $fy] = $this->makeFyWithRows();
        // 12500000 - (200000 + 0) = 12300000
        $this->assertSame(12300000.0, (float) (new FiscalYearAggregator())->netPl($fy));
    }

    public function test_net_pl_excludes_cash_received_recoveries(): void
    {
        [$c, $fy] = $this->makeFyWithRows();
        // Add a loan section + entry + inbound recovery payment.
        $loanSection = $c->sections()->where('slug', 'loan')->firstOrFail();
        $loanEntry = Entry::create([
            'company_id' => $c->id,
            'fiscal_year_id' => $fy->id,
            'section_id' => $loanSection->id,
            'title' => 'Loan to vendor',
            'loan_amount' => 500000,
        ]);
        EntryPayment::create([
            'entry_id' => $loanEntry->id,
            'occurred_on' => '2025-08-10',
            'amount' => 150000,
            'direction' => 'in',
            'mode' => 'bank',
        ]);

        $agg = new FiscalYearAggregator();
        // Recoveries land in Cash Received but MUST NOT alter Net P/L.
        $this->assertSame(150000.0, (float) $agg->cashInflowFromRecoveries($fy));
        $this->assertSame(12300000.0, (float) $agg->netPl($fy));
    }

    public function test_cash_received_excludes_inbound_payments_on_salary_or_expense(): void
    {
        [$c, $fy] = $this->makeFyWithRows();
        // Quirky inbound on salary (e.g. a refund booked on the wrong row).
        $salary = $c->sections()->where('slug', 'salary')->firstOrFail();
        $salaryEntry = Entry::create([
            'company_id' => $c->id, 'fiscal_year_id' => $fy->id,
            'section_id' => $salary->id, 'title' => 'Refunded',
        ]);
        EntryPayment::create([
            'entry_id' => $salaryEntry->id, 'occurred_on' => '2025-07-01',
            'amount' => 999999, 'direction' => 'in', 'mode' => 'bank',
        ]);

        // Legit recovery on a loan-given row → SHOULD count.
        $loan = $c->sections()->where('slug', 'loan')->firstOrFail();
        $loanEntry = Entry::create([
            'company_id' => $c->id, 'fiscal_year_id' => $fy->id,
            'section_id' => $loan->id, 'title' => 'Vendor', 'loan_amount' => 500000,
        ]);
        EntryPayment::create([
            'entry_id' => $loanEntry->id, 'occurred_on' => '2025-08-01',
            'amount' => 100000, 'direction' => 'in', 'mode' => 'bank',
        ]);

        $agg = new FiscalYearAggregator();
        // Salary inbound is EXCLUDED. Loan recovery is INCLUDED.
        $this->assertSame(100000.0, (float) $agg->cashInflowFromRecoveries($fy));
    }

    public function test_cash_received_includes_loans_taken_and_receipts_sections(): void
    {
        [$c, $fy] = $this->makeFyWithRows();
        $taken = $c->sections()->where('slug', 'loans_taken')->firstOrFail();
        $takenEntry = Entry::create([
            'company_id' => $c->id, 'fiscal_year_id' => $fy->id,
            'section_id' => $taken->id, 'title' => 'HDFC', 'loan_amount' => 800000,
        ]);
        // Principal disbursed to us (inbound) — counts.
        EntryPayment::create([
            'entry_id' => $takenEntry->id, 'occurred_on' => '2025-04-15',
            'amount' => 800000, 'direction' => 'in', 'mode' => 'bank',
        ]);

        // Receipts section + Cash Received-style entry.
        $receipts = $c->sections()->create([
            'company_id' => $c->id, 'slug' => 'receipts',
            'name' => 'Receipts', 'kind' => 'generic', 'sort_order' => 99,
        ]);
        $receiptEntry = Entry::create([
            'company_id' => $c->id, 'fiscal_year_id' => $fy->id,
            'section_id' => $receipts->id, 'title' => 'Refund from someone',
        ]);
        EntryPayment::create([
            'entry_id' => $receiptEntry->id, 'occurred_on' => '2025-05-01',
            'amount' => 25000, 'direction' => 'in', 'mode' => 'cash',
        ]);

        $this->assertSame(825000.0,
            (float) (new FiscalYearAggregator())->cashInflowFromRecoveries($fy));
    }

    public function test_non_cash_outflow_sums_depreciation_across_asset_entries(): void
    {
        $c = \App\Models\Book\Company::factory()->create();
        $fy = \App\Models\Book\FiscalYear::factory()->create([
            'company_id' => $c->id,
            'start_date' => '2025-04-01', 'end_date' => '2026-03-31', 'label' => '2025-26',
        ]);
        $assetSection = $c->sections()->where('slug', 'assets')->first();
        $entry = \App\Models\Book\Entry::create([
            'company_id' => $c->id, 'fiscal_year_id' => $fy->id,
            'section_id' => $assetSection->id, 'title' => 'Car',
        ]);
        \App\Models\Book\Asset::create([
            'entry_id' => $entry->id, 'original_value' => 300000,
            'dep_percent' => 20, 'dep_years' => 5,
            'dep_started_at' => '2025-04-01', 'method' => 'straight_line',
        ]);

        $agg = new \App\Books\Services\FiscalYearAggregator();
        $this->assertSame(60000.0, (float) $agg->nonCashOutflow($fy));
    }
}
