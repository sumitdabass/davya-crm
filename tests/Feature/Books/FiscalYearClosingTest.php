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

    public function test_blocks_updates_to_entries_in_a_closed_fy(): void
    {
        $c = Company::factory()->create();
        $fy = FiscalYear::factory()->create(['company_id' => $c->id]); // open
        $s = Section::factory()->create(['company_id' => $c->id]);

        $e = Entry::create([
            'company_id' => $c->id,
            'fiscal_year_id' => $fy->id,
            'section_id' => $s->id,
            'title' => 'pre-close',
            'salary_amount' => 100,
        ]);

        $fy->update(['is_closed' => true]);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessageMatches('/closed/');

        $e->update(['salary_amount' => 999]);
    }

    public function test_blocks_deletes_to_entries_in_a_closed_fy(): void
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

        $fy->update(['is_closed' => true]);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessageMatches('/closed/');

        $e->delete();
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

    public function test_close_fy_action_freezes_the_year(): void
    {
        config()->set('books.enabled', true);
        \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'super_admin']);
        $u = \App\Models\User::factory()->create(['is_active' => true, 'must_change_password' => false]);
        $u->assignRole('super_admin');
        $this->actingAs($u);

        $c = \App\Models\Book\Company::create(['name' => 'A', 'slug' => 'a']);
        $fy = \App\Models\Book\FiscalYear::create(['company_id' => $c->id,
            'start_date' => '2025-04-01', 'end_date' => '2026-03-31', 'label' => '2025-26']);

        \Livewire\Livewire::test(\App\Filament\Pages\Book\CompanyDashboard::class,
            ['company' => 'a', 'fy' => '2025-26'])
            ->callAction('closeFy')
            ->assertHasNoActionErrors();

        $this->assertTrue($fy->fresh()->is_closed);
        $this->assertNotNull($fy->fresh()->closing_summary);
    }

    public function test_reopen_fy_action_clears_snapshot(): void
    {
        config()->set('books.enabled', true);
        \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'super_admin']);
        $u = \App\Models\User::factory()->create(['is_active' => true, 'must_change_password' => false]);
        $u->assignRole('super_admin');
        $this->actingAs($u);

        $c = \App\Models\Book\Company::create(['name' => 'A', 'slug' => 'a']);
        $fy = \App\Models\Book\FiscalYear::create(['company_id' => $c->id,
            'start_date' => '2025-04-01', 'end_date' => '2026-03-31', 'label' => '2025-26',
            'is_closed' => true, 'closing_summary_json' => ['net_pl' => 1234]]);

        \Livewire\Livewire::test(\App\Filament\Pages\Book\CompanyDashboard::class,
            ['company' => 'a', 'fy' => '2025-26'])
            ->callAction('reopenFy')
            ->assertHasNoActionErrors();

        $this->assertFalse($fy->fresh()->is_closed);
        $this->assertNull($fy->fresh()->closing_summary_json);
    }

    public function test_new_fy_action_creates_the_year(): void
    {
        config()->set('books.enabled', true);
        \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'super_admin']);
        $u = \App\Models\User::factory()->create(['is_active' => true, 'must_change_password' => false]);
        $u->assignRole('super_admin');
        $this->actingAs($u);

        $c = \App\Models\Book\Company::create(['name' => 'A', 'slug' => 'a']);
        $fy = \App\Models\Book\FiscalYear::create(['company_id' => $c->id,
            'start_date' => '2025-04-01', 'end_date' => '2026-03-31', 'label' => '2025-26']);

        \Livewire\Livewire::test(\App\Filament\Pages\Book\CompanyDashboard::class,
            ['company' => 'a', 'fy' => '2025-26'])
            ->callAction('newFy', [
                'label' => '2026-27',
                'start_date' => '2026-04-01',
                'end_date' => '2027-03-31',
            ])
            ->assertHasNoActionErrors();

        $this->assertDatabaseHas('book_fiscal_years', [
            'company_id' => $c->id, 'label' => '2026-27',
        ]);
    }
}
