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

/**
 * Regression: Filament 3 silently refuses mountAction() while another action
 * is already mounted. The viewPayments modal is itself a mounted action, so
 * the Edit/Delete bridges must unmountAction() first or the new action never
 * appears. Without this, both Edit and Delete buttons inside View Payments
 * appear to do nothing in the browser.
 */
class PaymentModalStackingTest extends TestCase
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

    private function seedEntryAndPayment(): array
    {
        $c = Company::create(['name' => 'A', 'slug' => 'a']);
        $fy = FiscalYear::create(['company_id' => $c->id, 'start_date' => '2025-04-01',
            'end_date' => '2026-03-31', 'label' => '2025-26']);
        $s = $c->sections()->where('slug', 'salary')->first();
        $entry = Entry::create(['company_id' => $c->id, 'fiscal_year_id' => $fy->id,
            'section_id' => $s->id, 'title' => '3lip cup', 'salary_amount' => 458472]);
        $payment = EntryPayment::create([
            'entry_id' => $entry->id, 'direction' => 'out', 'amount' => 458472,
            'mode' => 'bank', 'occurred_on' => '2025-06-01',
        ]);

        return [$entry, $payment];
    }

    public function test_edit_payment_bridge_replaces_viewpayments_modal_with_edit_modal(): void
    {
        [$entry, $payment] = $this->seedEntryAndPayment();

        $component = Livewire::test(SectionPage::class,
            ['company' => 'a', 'fy' => '2025-26', 'section' => 'salary'])
            ->mountAction('viewPayments', ['id' => $entry->id]);

        $this->assertSame(['viewPayments'], $component->instance()->mountedActions,
            'precondition: viewPayments should be the mounted action');

        $component->dispatch('book:open-edit-payment', id: $payment->id);

        $this->assertSame(['editPayment'], $component->instance()->mountedActions,
            'editPayment should replace viewPayments on the mountedActions stack');
    }

    public function test_delete_payment_bridge_closes_viewpayments_and_deletes_the_row(): void
    {
        [$entry, $payment] = $this->seedEntryAndPayment();

        Livewire::test(SectionPage::class,
            ['company' => 'a', 'fy' => '2025-26', 'section' => 'salary'])
            ->mountAction('viewPayments', ['id' => $entry->id])
            ->dispatch('book:open-delete-payment', id: $payment->id);

        // deletePayment has no modal/confirmation — once mounted it auto-executes
        // and removes the payment row.
        $this->assertNull(EntryPayment::find($payment->id),
            'payment row should be deleted after the bridge closes viewPayments and mounts deletePayment');
    }
}
