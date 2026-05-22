<?php

namespace Tests\Feature\Books;

use App\Filament\Pages\Book\SectionPage;
use App\Models\Book\Company;
use App\Models\Book\Entry;
use App\Models\Book\EntryPayment;
use App\Models\Book\FiscalYear;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class EntryPaymentUITest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('books.enabled', true);
        Role::firstOrCreate(['name' => 'super_admin']);
        $u = User::factory()->create(['is_active' => true, 'must_change_password' => false]);
        $u->assignRole('super_admin');
        $this->actingAs($u);
    }

    public function test_add_payment_action_creates_record(): void
    {
        $c = Company::create(['name' => 'A', 'slug' => 'a']);
        $fy = FiscalYear::create(['company_id' => $c->id, 'start_date' => '2025-04-01',
            'end_date' => '2026-03-31', 'label' => '2025-26']);
        $s = $c->sections()->where('slug', 'salary')->first();
        $entry = Entry::create(['company_id' => $c->id, 'fiscal_year_id' => $fy->id,
            'section_id' => $s->id, 'title' => 'Usha', 'salary_amount' => 1200000]);

        Livewire::test(SectionPage::class,
            ['company' => 'a', 'fy' => '2025-26', 'section' => 'salary'])
            ->mountAction('addPayment', ['id' => $entry->id])
            ->setActionData([
                'direction' => 'out', 'amount' => 50000, 'mode' => 'bank',
                'occurred_on' => '2025-06-01',
            ])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $this->assertSame(1, $entry->fresh()->payments()->count());
        $this->assertSame('out', $entry->payments()->first()->direction);
        $this->assertSame('bank', $entry->payments()->first()->mode);
    }

    public function test_paid_accessor_updates_after_adding_payment(): void
    {
        $c = Company::create(['name' => 'A', 'slug' => 'a']);
        $fy = FiscalYear::create(['company_id' => $c->id, 'start_date' => '2025-04-01',
            'end_date' => '2026-03-31', 'label' => '2025-26']);
        $s = $c->sections()->where('slug', 'salary')->first();
        $entry = Entry::create(['company_id' => $c->id, 'fiscal_year_id' => $fy->id,
            'section_id' => $s->id, 'title' => 'Usha', 'salary_amount' => 1200000]);

        Livewire::test(SectionPage::class,
            ['company' => 'a', 'fy' => '2025-26', 'section' => 'salary'])
            ->mountAction('addPayment', ['id' => $entry->id])
            ->setActionData(['direction' => 'out', 'amount' => 200000, 'mode' => 'cash',
                'occurred_on' => '2025-06-01'])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $this->assertSame(200000.0, (float) $entry->fresh()->paid);
        $this->assertSame(1000000.0, (float) $entry->fresh()->balance);
    }

    public function test_delete_payment_action_removes_record(): void
    {
        $c = Company::create(['name' => 'A', 'slug' => 'a']);
        $fy = FiscalYear::create(['company_id' => $c->id, 'start_date' => '2025-04-01',
            'end_date' => '2026-03-31', 'label' => '2025-26']);
        $s = $c->sections()->where('slug', 'salary')->first();
        $entry = Entry::create(['company_id' => $c->id, 'fiscal_year_id' => $fy->id,
            'section_id' => $s->id, 'title' => 'Usha']);
        $p = EntryPayment::create(['entry_id' => $entry->id, 'amount' => 100,
            'direction' => 'out', 'mode' => 'bank', 'occurred_on' => '2025-06-01']);

        Livewire::test(SectionPage::class,
            ['company' => 'a', 'fy' => '2025-26', 'section' => 'salary'])
            ->callAction('deletePayment', [], arguments: ['id' => $p->id])
            ->assertHasNoActionErrors();

        $this->assertSame(0, $entry->fresh()->payments()->count());
    }

    public function test_add_payment_blocked_when_fy_is_closed(): void
    {
        $c = Company::create(['name' => 'A', 'slug' => 'a']);
        $fy = FiscalYear::create(['company_id' => $c->id, 'start_date' => '2025-04-01',
            'end_date' => '2026-03-31', 'label' => '2025-26']);
        $s = $c->sections()->where('slug', 'salary')->first();
        $entry = Entry::create(['company_id' => $c->id, 'fiscal_year_id' => $fy->id,
            'section_id' => $s->id, 'title' => 'Usha']);
        // Now close the FY (after the entry is in place).
        $fy->forceFill(['is_closed' => true])->saveQuietly();

        $this->expectException(\DomainException::class);
        Livewire::test(SectionPage::class,
            ['company' => 'a', 'fy' => '2025-26', 'section' => 'salary'])
            ->mountAction('addPayment', ['id' => $entry->id])
            ->setActionData(['direction' => 'out', 'amount' => 100, 'mode' => 'cash',
                'occurred_on' => '2025-06-01'])
            ->callMountedAction();
    }

    public function test_edit_payment_action_updates_record(): void
    {
        $c = \App\Models\Book\Company::create(['name' => 'A', 'slug' => 'a']);
        $fy = \App\Models\Book\FiscalYear::create(['company_id' => $c->id, 'start_date' => '2025-04-01',
            'end_date' => '2026-03-31', 'label' => '2025-26']);
        $s = $c->sections()->where('slug', 'salary')->first();
        $entry = \App\Models\Book\Entry::create(['company_id' => $c->id, 'fiscal_year_id' => $fy->id,
            'section_id' => $s->id, 'title' => 'Usha']);
        $p = \App\Models\Book\EntryPayment::create([
            'entry_id' => $entry->id, 'amount' => 100, 'direction' => 'out',
            'mode' => 'cash', 'occurred_on' => '2025-06-01',
        ]);

        \Livewire\Livewire::test(\App\Filament\Pages\Book\SectionPage::class,
            ['company' => 'a', 'fy' => '2025-26', 'section' => 'salary'])
            ->mountAction('editPayment', ['id' => $p->id])
            ->setActionData([
                'direction' => 'in',
                'amount' => 250,
                'mode' => 'bank',
                'occurred_on' => '2025-07-15',
                'reference' => 'TXN-001',
            ])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $p->refresh();
        $this->assertSame('in', $p->direction);
        $this->assertSame(250.0, (float) $p->amount);
        $this->assertSame('bank', $p->mode);
        $this->assertSame('TXN-001', $p->reference);
    }

    public function test_add_payment_persists_source(): void
    {
        $c = Company::create(['name' => 'A', 'slug' => 'a']);
        $fy = FiscalYear::create(['company_id' => $c->id, 'start_date' => '2025-04-01',
            'end_date' => '2026-03-31', 'label' => '2025-26']);
        $s = $c->sections()->where('slug', 'salary')->first();
        $entry = Entry::create(['company_id' => $c->id, 'fiscal_year_id' => $fy->id,
            'section_id' => $s->id, 'title' => 'Usha']);

        Livewire::test(SectionPage::class,
            ['company' => 'a', 'fy' => '2025-26', 'section' => 'salary'])
            ->mountAction('addPayment', ['id' => $entry->id])
            ->setActionData([
                'direction' => 'out', 'amount' => 1000, 'mode' => 'bank',
                'occurred_on' => '2025-06-01',
                'source' => 'Vendor X',
            ])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $this->assertSame('Vendor X', $entry->fresh()->payments()->first()->source);
    }

    public function test_edit_payment_updates_source(): void
    {
        $c = Company::create(['name' => 'A', 'slug' => 'a']);
        $fy = FiscalYear::create(['company_id' => $c->id, 'start_date' => '2025-04-01',
            'end_date' => '2026-03-31', 'label' => '2025-26']);
        $s = $c->sections()->where('slug', 'salary')->first();
        $entry = Entry::create(['company_id' => $c->id, 'fiscal_year_id' => $fy->id,
            'section_id' => $s->id, 'title' => 'Usha']);
        $p = EntryPayment::create(['entry_id' => $entry->id, 'amount' => 100,
            'direction' => 'out', 'mode' => 'cash', 'occurred_on' => '2025-06-01',
            'source' => 'Old']);

        Livewire::test(SectionPage::class,
            ['company' => 'a', 'fy' => '2025-26', 'section' => 'salary'])
            ->mountAction('editPayment', ['id' => $p->id])
            ->setActionData([
                'direction' => 'out', 'amount' => 100, 'mode' => 'cash',
                'occurred_on' => '2025-06-01',
                'source' => 'New Source',
            ])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $this->assertSame('New Source', $p->fresh()->source);
    }

    public function test_add_payment_default_direction_is_in_on_loans_given_section(): void
    {
        // Regression: previously the default direction was computed from
        // entry.loan_amount, so a Loans Given row with principal=0 would
        // default to 'out'. Now driven by section.kind — should always be 'in'
        // on a Loans Given (slug='loan') section regardless of entry amount.
        $c = Company::create(['name' => 'A', 'slug' => 'a']);
        $fy = FiscalYear::create(['company_id' => $c->id, 'start_date' => '2025-04-01',
            'end_date' => '2026-03-31', 'label' => '2025-26']);
        $s = $c->sections()->where('slug', 'loan')->first();
        $entry = Entry::create([
            'company_id' => $c->id, 'fiscal_year_id' => $fy->id,
            'section_id' => $s->id, 'title' => 'Kyne',
            'loan_amount' => 0, 'salary_amount' => 0,
        ]);

        Livewire::test(SectionPage::class,
            ['company' => 'a', 'fy' => '2025-26', 'section' => 'loan'])
            ->mountAction('addPayment', ['id' => $entry->id])
            ->setActionData(['amount' => 1000, 'mode' => 'bank',
                'occurred_on' => '2025-06-01'])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $this->assertSame('in', $entry->fresh()->payments()->first()->direction);
    }

    public function test_add_payment_default_direction_is_out_on_loans_taken_section(): void
    {
        $c = Company::create(['name' => 'A', 'slug' => 'a']);
        $fy = FiscalYear::create(['company_id' => $c->id, 'start_date' => '2025-04-01',
            'end_date' => '2026-03-31', 'label' => '2025-26']);
        $s = $c->sections()->where('slug', 'loans_taken')->first();
        $entry = Entry::create([
            'company_id' => $c->id, 'fiscal_year_id' => $fy->id,
            'section_id' => $s->id, 'title' => 'Bank Loan',
            'loan_amount' => 500000,
        ]);

        Livewire::test(SectionPage::class,
            ['company' => 'a', 'fy' => '2025-26', 'section' => 'loans_taken'])
            ->mountAction('addPayment', ['id' => $entry->id])
            ->setActionData(['amount' => 5000, 'mode' => 'bank',
                'occurred_on' => '2025-06-01'])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $this->assertSame('out', $entry->fresh()->payments()->first()->direction);
    }

    public function test_edit_payment_blocked_when_fy_is_closed(): void
    {
        $c = \App\Models\Book\Company::create(['name' => 'A', 'slug' => 'a']);
        $fy = \App\Models\Book\FiscalYear::create(['company_id' => $c->id, 'start_date' => '2025-04-01',
            'end_date' => '2026-03-31', 'label' => '2025-26']);
        $s = $c->sections()->where('slug', 'salary')->first();
        $entry = \App\Models\Book\Entry::create(['company_id' => $c->id, 'fiscal_year_id' => $fy->id,
            'section_id' => $s->id, 'title' => 'Usha']);
        $p = \App\Models\Book\EntryPayment::create([
            'entry_id' => $entry->id, 'amount' => 100, 'direction' => 'out',
            'mode' => 'cash', 'occurred_on' => '2025-06-01',
        ]);
        $fy->forceFill(['is_closed' => true])->saveQuietly();

        $this->expectException(\DomainException::class);
        \Livewire\Livewire::test(\App\Filament\Pages\Book\SectionPage::class,
            ['company' => 'a', 'fy' => '2025-26', 'section' => 'salary'])
            ->mountAction('editPayment', ['id' => $p->id])
            ->setActionData([
                'direction' => 'in', 'amount' => 250, 'mode' => 'bank',
                'occurred_on' => '2025-07-15',
            ])
            ->callMountedAction();
    }
}
