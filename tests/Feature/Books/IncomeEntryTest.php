<?php

namespace Tests\Feature\Books;

use App\Models\Book\Company;
use App\Models\Book\FiscalYear;
use App\Models\Book\IncomeEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IncomeEntryTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_an_income_entry_scoped_to_company_and_fy(): void
    {
        $c = Company::factory()->create();
        $fy = FiscalYear::factory()->create(['company_id' => $c->id]);

        $i = IncomeEntry::create([
            'company_id' => $c->id,
            'fiscal_year_id' => $fy->id,
            'occurred_on' => '2025-05-15',
            'source' => 'Client A',
            'amount' => 500000,
            'notes' => 'invoice INV-001',
        ]);

        $this->assertSame('Client A', $i->source);
        $this->assertSame(500000.0, (float) $i->amount);
    }

    public function test_sums_income_per_fy(): void
    {
        $c = Company::factory()->create();
        $fy = FiscalYear::factory()->create(['company_id' => $c->id]);

        IncomeEntry::create([
            'company_id' => $c->id,
            'fiscal_year_id' => $fy->id,
            'occurred_on' => '2025-05-15',
            'source' => 'Client A',
            'amount' => 1000000,
        ]);

        IncomeEntry::create([
            'company_id' => $c->id,
            'fiscal_year_id' => $fy->id,
            'occurred_on' => '2025-05-20',
            'source' => 'Client B',
            'amount' => 500000,
        ]);

        $this->assertSame(
            1500000.0,
            (float) IncomeEntry::where('fiscal_year_id', $fy->id)->sum('amount')
        );
    }
}
