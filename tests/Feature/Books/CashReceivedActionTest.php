<?php

namespace Tests\Feature\Books;

use App\Books\Services\FiscalYearAggregator;
use App\Filament\Pages\Book\CompanyDashboard;
use App\Models\Book\Company;
use App\Models\Book\Entry;
use App\Models\Book\EntryPayment;
use App\Models\Book\FiscalYear;
use App\Models\Book\Section;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CashReceivedActionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('books.enabled', true);
        Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
        $u = User::factory()->create(['is_active' => true, 'must_change_password' => false]);
        $u->assignRole('super_admin');
        $this->actingAs($u);
    }

    private function makeCompanyAndFy(): array
    {
        $c = Company::create(['name' => 'A', 'slug' => 'a']);
        $fy = FiscalYear::create([
            'company_id' => $c->id,
            'start_date' => '2025-04-01',
            'end_date' => '2026-03-31',
            'label' => '2025-26',
        ]);

        return [$c, $fy];
    }

    public function test_action_creates_receipts_section_when_absent(): void
    {
        [$c, $fy] = $this->makeCompanyAndFy();
        $this->assertNull($c->sections()->where('slug', 'receipts')->first());

        Livewire::test(CompanyDashboard::class, ['company' => 'a', 'fy' => '2025-26'])
            ->callAction('cashReceived', data: [
                'source' => 'Refund',
                'amount' => 25000,
                'occurred_on' => '2025-06-15',
                'mode' => 'bank',
            ])
            ->assertHasNoActionErrors();

        $section = $c->sections()->where('slug', 'receipts')->first();
        $this->assertNotNull($section);
        $this->assertSame('Receipts', $section->name);
        $this->assertSame('generic', $section->kind);
    }

    public function test_action_creates_entry_and_inbound_payment_with_source(): void
    {
        [$c, $fy] = $this->makeCompanyAndFy();

        Livewire::test(CompanyDashboard::class, ['company' => 'a', 'fy' => '2025-26'])
            ->callAction('cashReceived', data: [
                'source' => 'Sumit Loan back',
                'amount' => 50000,
                'occurred_on' => '2025-07-01',
                'mode' => 'upi',
            ])
            ->assertHasNoActionErrors();

        $section = $c->sections()->where('slug', 'receipts')->firstOrFail();
        $entry = Entry::where('section_id', $section->id)->firstOrFail();
        $this->assertSame('Sumit Loan back', $entry->title);
        $this->assertSame($fy->id, $entry->fiscal_year_id);

        $payment = EntryPayment::where('entry_id', $entry->id)->firstOrFail();
        $this->assertSame('in', $payment->direction);
        $this->assertSame(50000.0, (float) $payment->amount);
        $this->assertSame('upi', $payment->mode);
        $this->assertSame('Sumit Loan back', $payment->source);
    }

    public function test_action_reuses_existing_receipts_section(): void
    {
        [$c, $fy] = $this->makeCompanyAndFy();
        Section::create([
            'company_id' => $c->id, 'slug' => 'receipts',
            'name' => 'Receipts', 'kind' => 'generic', 'sort_order' => 99,
        ]);

        Livewire::test(CompanyDashboard::class, ['company' => 'a', 'fy' => '2025-26'])
            ->callAction('cashReceived', data: [
                'source' => 'Other',
                'amount' => 1000,
                'occurred_on' => '2025-06-15',
                'mode' => 'cash',
            ])
            ->assertHasNoActionErrors();

        // Still exactly one receipts section.
        $this->assertSame(1, $c->sections()->where('slug', 'receipts')->count());
    }

    public function test_action_increments_cash_received_kpi_but_not_net_pl(): void
    {
        [$c, $fy] = $this->makeCompanyAndFy();
        $agg = new FiscalYearAggregator();
        $netBefore = $agg->netPl($fy);

        Livewire::test(CompanyDashboard::class, ['company' => 'a', 'fy' => '2025-26'])
            ->callAction('cashReceived', data: [
                'source' => 'Refund',
                'amount' => 75000,
                'occurred_on' => '2025-08-01',
                'mode' => 'bank',
            ])
            ->assertHasNoActionErrors();

        $fy->refresh();
        $this->assertSame(75000.0, (float) $agg->cashInflowFromRecoveries($fy));
        $this->assertSame($netBefore, $agg->netPl($fy)); // Net P/L unaffected by recovery.
    }

    public function test_action_blocked_when_fy_is_closed(): void
    {
        [$c, $fy] = $this->makeCompanyAndFy();
        $fy->forceFill(['is_closed' => true])->saveQuietly();

        // Visible() hides the button so the action no longer mounts.
        Livewire::test(CompanyDashboard::class, ['company' => 'a', 'fy' => '2025-26'])
            ->assertActionHidden('cashReceived');
    }

    public function test_action_form_defaults_source_to_other_and_today(): void
    {
        [$c, $fy] = $this->makeCompanyAndFy();

        Livewire::test(CompanyDashboard::class, ['company' => 'a', 'fy' => '2025-26'])
            ->mountAction('cashReceived')
            ->assertActionDataSet([
                'source' => 'Other',
                'mode' => 'bank',
                'occurred_on' => now()->toDateString(),
            ]);
    }
}
