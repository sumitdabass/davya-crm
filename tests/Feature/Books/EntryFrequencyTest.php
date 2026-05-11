<?php

namespace Tests\Feature\Books;

use App\Models\Book\Company;
use App\Models\Book\Entry;
use App\Models\Book\FiscalYear;
use App\Models\Book\Section;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EntryFrequencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_one_time_is_the_default(): void
    {
        $c = Company::factory()->create();
        $fy = FiscalYear::factory()->create(['company_id' => $c->id]);
        $s = Section::factory()->create(['company_id' => $c->id]);
        $e = Entry::create(['company_id' => $c->id, 'fiscal_year_id' => $fy->id,
            'section_id' => $s->id, 'title' => 'X', 'salary_amount' => 100]);
        $this->assertSame('one_time', $e->frequency);
        $this->assertSame(100.0, $e->annualized_salary_amount);
    }

    public function test_monthly_frequency_multiplies_by_12(): void
    {
        $c = Company::factory()->create();
        $fy = FiscalYear::factory()->create(['company_id' => $c->id]);
        $s = Section::factory()->create(['company_id' => $c->id]);
        $e = Entry::create(['company_id' => $c->id, 'fiscal_year_id' => $fy->id,
            'section_id' => $s->id, 'title' => 'Rent', 'salary_amount' => 45000,
            'frequency' => 'monthly']);
        $this->assertSame(540000.0, $e->annualized_salary_amount);
        $this->assertSame(12, $e->periods_per_year);
    }

    public function test_invalid_frequency_throws(): void
    {
        $c = Company::factory()->create();
        $fy = FiscalYear::factory()->create(['company_id' => $c->id]);
        $s = Section::factory()->create(['company_id' => $c->id]);

        $this->expectException(\InvalidArgumentException::class);
        Entry::create(['company_id' => $c->id, 'fiscal_year_id' => $fy->id,
            'section_id' => $s->id, 'title' => 'X', 'salary_amount' => 100,
            'frequency' => 'fortnightly']);
    }
}
