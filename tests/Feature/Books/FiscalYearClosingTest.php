<?php

namespace Tests\Feature\Books;

use App\Books\Services\ClosingSnapshotWriter;
use App\Models\Book\Company;
use App\Models\Book\Entry;
use App\Models\Book\EntryPayment;
use App\Models\Book\FiscalYear;
use App\Models\Book\IncomeEntry;
use App\Models\Book\Section;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FiscalYearClosingTest extends TestCase
{
    use RefreshDatabase;

    public function test_writes_a_closing_snapshot_on_close(): void
    {
        $c = Company::factory()->create();
        $fy = FiscalYear::factory()->create(['company_id' => $c->id]);

        IncomeEntry::create([
            'company_id' => $c->id,
            'fiscal_year_id' => $fy->id,
            'occurred_on' => '2025-04-15',
            'source' => 'A',
            'amount' => 1000000,
        ]);

        (new ClosingSnapshotWriter())->close($fy);
        $fy->refresh();

        $this->assertTrue($fy->is_closed);
        $this->assertSame(1000000.0, (float) $fy->closing_summary['total_income']);
    }

    public function test_nulls_snapshot_on_reopen(): void
    {
        $c = Company::factory()->create();
        $fy = FiscalYear::factory()->create(['company_id' => $c->id]);

        (new ClosingSnapshotWriter())->close($fy);
        (new ClosingSnapshotWriter())->reopen($fy->fresh());
        $fy->refresh();

        $this->assertFalse($fy->is_closed);
        $this->assertNull($fy->closing_summary_json);
    }

    public function test_blocks_writes_to_entries_in_a_closed_fy(): void
    {
        $c = Company::factory()->create();
        $fy = FiscalYear::factory()->create(['company_id' => $c->id, 'is_closed' => true]);
        $s = Section::factory()->create(['company_id' => $c->id]);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessageMatches('/closed/');

        Entry::create([
            'company_id' => $c->id,
            'fiscal_year_id' => $fy->id,
            'section_id' => $s->id,
            'title' => 'late row',
        ]);
    }

    public function test_blocks_writes_to_payments_whose_entry_is_in_a_closed_fy(): void
    {
        $c = Company::factory()->create();
        $fy = FiscalYear::factory()->create(['company_id' => $c->id]); // open
        $s = Section::factory()->create(['company_id' => $c->id]);

        $e = Entry::create([
            'company_id' => $c->id,
            'fiscal_year_id' => $fy->id,
            'section_id' => $s->id,
            'title' => 'pre-close',
        ]);

        $fy->update(['is_closed' => true]); // close after entry exists

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessageMatches('/closed/');

        EntryPayment::create([
            'entry_id' => $e->id,
            'amount' => 100,
            'direction' => 'out',
            'mode' => 'bank',
            'occurred_on' => '2025-05-01',
        ]);
    }

    public function test_blocks_writes_to_income_in_a_closed_fy(): void
    {
        $c = Company::factory()->create();
        $fy = FiscalYear::factory()->create(['company_id' => $c->id, 'is_closed' => true]);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessageMatches('/closed/');

        IncomeEntry::create([
            'company_id' => $c->id,
            'fiscal_year_id' => $fy->id,
            'occurred_on' => '2025-05-01',
            'source' => 'A',
            'amount' => 1,
        ]);
    }
}
