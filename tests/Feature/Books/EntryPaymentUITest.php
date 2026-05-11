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
}
