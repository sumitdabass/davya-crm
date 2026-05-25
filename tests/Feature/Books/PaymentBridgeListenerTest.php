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

class PaymentBridgeListenerTest extends TestCase
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
            'section_id' => $s->id, 'title' => 'Usha', 'salary_amount' => 1200000]);
        $payment = EntryPayment::create([
            'entry_id' => $entry->id, 'direction' => 'out', 'amount' => 10000,
            'mode' => 'cash', 'occurred_on' => '2025-06-01',
        ]);

        return [$entry, $payment];
    }

    public function test_open_edit_payment_event_mounts_edit_action(): void
    {
        [, $payment] = $this->seedEntryAndPayment();

        $component = Livewire::test(SectionPage::class,
            ['company' => 'a', 'fy' => '2025-26', 'section' => 'salary'])
            ->dispatch('book:open-edit-payment', id: $payment->id);

        $this->assertNotEmpty($component->instance()->mountedActions,
            'editPayment action should be mounted after book:open-edit-payment fires');
        $this->assertSame('editPayment', end($component->instance()->mountedActions));
    }

    public function test_open_delete_payment_event_actually_deletes_the_payment(): void
    {
        [, $payment] = $this->seedEntryAndPayment();

        Livewire::test(SectionPage::class,
            ['company' => 'a', 'fy' => '2025-26', 'section' => 'salary'])
            ->dispatch('book:open-delete-payment', id: $payment->id);

        // deletePayment action has no modal/confirmation — mountAction auto-executes
        // on mount, deleting the row. The View Payments partial doesn't re-fetch
        // (stale modal content), which is the actual UX bug the user reports.
        $this->assertNull(EntryPayment::find($payment->id),
            'Payment should be deleted after book:open-delete-payment fires');
    }

    public function test_edit_payment_action_updates_record(): void
    {
        [, $payment] = $this->seedEntryAndPayment();

        Livewire::test(SectionPage::class,
            ['company' => 'a', 'fy' => '2025-26', 'section' => 'salary'])
            ->mountAction('editPayment', ['id' => $payment->id])
            ->setActionData(['direction' => 'out', 'amount' => 25000, 'mode' => 'bank',
                'occurred_on' => '2025-07-01'])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $fresh = $payment->fresh();
        $this->assertSame('25000.00', (string) $fresh->amount);
        $this->assertSame('bank', $fresh->mode);
    }

    public function test_delete_payment_action_removes_record(): void
    {
        [, $payment] = $this->seedEntryAndPayment();

        Livewire::test(SectionPage::class,
            ['company' => 'a', 'fy' => '2025-26', 'section' => 'salary'])
            ->mountAction('deletePayment', ['id' => $payment->id])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $this->assertNull(EntryPayment::find($payment->id));
    }
}
