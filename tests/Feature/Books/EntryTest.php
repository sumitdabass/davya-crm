<?php

namespace Tests\Feature\Books;

use App\Models\Book\Company;
use App\Models\Book\Entry;
use App\Models\Book\FiscalYear;
use App\Models\Book\Section;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EntryTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_an_entry_with_money_columns_defaulting_to_zero(): void
    {
        $c = Company::factory()->create();
        $fy = FiscalYear::factory()->create(['company_id' => $c->id]);
        $s = Section::factory()->create(['company_id' => $c->id]);

        $e = Entry::create([
            'company_id' => $c->id,
            'fiscal_year_id' => $fy->id,
            'section_id' => $s->id,
            'title' => 'Usha',
            'salary_amount' => 1200000,
        ]);

        $this->assertSame(1200000.0, (float) $e->salary_amount);
        $this->assertSame(0.0, (float) $e->loan_amount);
        $this->assertFalse($e->is_loan);
    }

    public function test_flags_is_loan_when_loan_amount_greater_than_zero(): void
    {
        $c = Company::factory()->create();
        $fy = FiscalYear::factory()->create(['company_id' => $c->id]);
        $s = Section::factory()->create(['company_id' => $c->id]);

        $e = Entry::create([
            'company_id' => $c->id,
            'fiscal_year_id' => $fy->id,
            'section_id' => $s->id,
            'title' => 'Rakesh',
            'loan_amount' => 1000000,
        ]);

        $this->assertSame(1000000.0, (float) $e->loan_amount);
        $this->assertSame(0.0, (float) $e->salary_amount);
        $this->assertTrue($e->is_loan);
    }

    public function test_supports_both_salary_and_loan_on_the_same_entry(): void
    {
        $c = Company::factory()->create();
        $fy = FiscalYear::factory()->create(['company_id' => $c->id]);
        $s = Section::factory()->create(['company_id' => $c->id]);

        $e = Entry::create([
            'company_id' => $c->id,
            'fiscal_year_id' => $fy->id,
            'section_id' => $s->id,
            'title' => 'Mohan',
            'salary_amount' => 500000,
            'loan_amount' => 250000,
        ]);

        $this->assertSame(500000.0, (float) $e->salary_amount);
        $this->assertSame(250000.0, (float) $e->loan_amount);
        $this->assertTrue($e->is_loan);
    }

    public function test_allows_multiple_rows_with_same_title_in_one_section(): void
    {
        $c = Company::factory()->create();
        $fy = FiscalYear::factory()->create(['company_id' => $c->id]);
        $s = Section::factory()->create(['company_id' => $c->id]);

        $a = Entry::create([
            'company_id' => $c->id,
            'fiscal_year_id' => $fy->id,
            'section_id' => $s->id,
            'title' => 'Usha',
            'salary_amount' => 100000,
        ]);

        $b = Entry::create([
            'company_id' => $c->id,
            'fiscal_year_id' => $fy->id,
            'section_id' => $s->id,
            'title' => 'Usha',
            'salary_amount' => 200000,
        ]);

        $this->assertNotSame($a->id, $b->id);
        $this->assertIsInt($a->id);
        $this->assertIsInt($b->id);
    }
}
