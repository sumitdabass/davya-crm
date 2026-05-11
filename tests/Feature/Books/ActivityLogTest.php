<?php

namespace Tests\Feature\Books;

use App\Models\Book\Company;
use App\Models\Book\Entry;
use App\Models\Book\EntryPayment;
use App\Models\Book\FiscalYear;
use App\Models\Book\IncomeEntry;
use App\Models\Book\Section;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class ActivityLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_logs_entry_create_update_and_delete(): void
    {
        $c = Company::factory()->create();
        $fy = FiscalYear::factory()->create(['company_id' => $c->id]);
        $s = Section::factory()->create(['company_id' => $c->id]);

        $before = Activity::count();

        $e = Entry::create([
            'company_id' => $c->id,
            'fiscal_year_id' => $fy->id,
            'section_id' => $s->id,
            'title' => 'X',
            'salary_amount' => 100,
        ]);
        $e->update(['salary_amount' => 200]);
        $e->delete();

        $this->assertSame(3, Activity::count() - $before);
    }

    public function test_logs_payment_create_event(): void
    {
        $e = Entry::factory()->create();

        $before = Activity::count();

        EntryPayment::create([
            'entry_id' => $e->id,
            'amount' => 100,
            'direction' => 'out',
            'mode' => 'bank',
            'occurred_on' => '2025-05-01',
        ]);

        $this->assertSame(1, Activity::count() - $before);
    }

    public function test_logs_income_create_event(): void
    {
        $c = Company::factory()->create();
        $fy = FiscalYear::factory()->create(['company_id' => $c->id]);

        $before = Activity::count();

        IncomeEntry::create([
            'company_id' => $c->id,
            'fiscal_year_id' => $fy->id,
            'occurred_on' => '2025-05-01',
            'source' => 'A',
            'amount' => 1,
        ]);

        $this->assertSame(1, Activity::count() - $before);
    }
}
