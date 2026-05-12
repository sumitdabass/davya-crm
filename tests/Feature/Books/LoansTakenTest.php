<?php

namespace Tests\Feature\Books;

use App\Filament\Pages\Book\CompanyDashboard;
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

class LoansTakenTest extends TestCase
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

    public function test_seeder_creates_loans_given_and_loans_taken_sections(): void
    {
        $c = Company::create(['name' => 'X', 'slug' => 'x']);

        $given = $c->sections()->where('slug', 'loan')->first();
        $taken = $c->sections()->where('slug', 'loans_taken')->first();

        $this->assertNotNull($given);
        $this->assertSame('Loans Given', $given->name);
        $this->assertNotNull($taken);
        $this->assertSame('Loans Taken', $taken->name);
        $this->assertSame('generic', $taken->kind);
    }

    public function test_loans_taken_outstanding_is_loan_amount_minus_repayments(): void
    {
        [$c, $fy] = $this->makeCompanyAndFy();
        $taken = $c->sections()->where('slug', 'loans_taken')->firstOrFail();
        $entry = Entry::create([
            'company_id' => $c->id, 'fiscal_year_id' => $fy->id,
            'section_id' => $taken->id, 'title' => 'HDFC Bank',
            'loan_amount' => 1000000, 'interest_rate' => '8.5% pa',
        ]);
        // Two repayments going out.
        EntryPayment::create(['entry_id' => $entry->id, 'amount' => 100000,
            'direction' => 'out', 'mode' => 'bank', 'occurred_on' => '2025-06-01']);
        EntryPayment::create(['entry_id' => $entry->id, 'amount' => 50000,
            'direction' => 'out', 'mode' => 'bank', 'occurred_on' => '2025-07-01']);

        $entry->refresh();
        $this->assertSame(150000.0, (float) $entry->repaid);
        $this->assertSame(850000.0, (float) $entry->loan_outstanding_taken);
    }

    public function test_dashboard_kpis_include_separate_loan_outstandings(): void
    {
        [$c, $fy] = $this->makeCompanyAndFy();
        $given = $c->sections()->where('slug', 'loan')->firstOrFail();
        $taken = $c->sections()->where('slug', 'loans_taken')->firstOrFail();

        // Loan we gave: 500K principal, 200K received back → 300K owed to us.
        $g = Entry::create(['company_id' => $c->id, 'fiscal_year_id' => $fy->id,
            'section_id' => $given->id, 'title' => 'Vendor X', 'loan_amount' => 500000]);
        EntryPayment::create(['entry_id' => $g->id, 'amount' => 200000,
            'direction' => 'in', 'mode' => 'bank', 'occurred_on' => '2025-06-01']);

        // Loan we took: 1M principal, 250K repaid → 750K we owe.
        $t = Entry::create(['company_id' => $c->id, 'fiscal_year_id' => $fy->id,
            'section_id' => $taken->id, 'title' => 'HDFC', 'loan_amount' => 1000000]);
        EntryPayment::create(['entry_id' => $t->id, 'amount' => 250000,
            'direction' => 'out', 'mode' => 'bank', 'occurred_on' => '2025-06-01']);

        $page = Livewire::test(CompanyDashboard::class, ['company' => 'a', 'fy' => '2025-26'])
            ->instance();
        $kpis = $page->getKpis();

        $this->assertSame(300000.0, $kpis['loans_given_outstanding']);
        $this->assertSame(750000.0, $kpis['loans_taken_outstanding']);
    }

    public function test_add_row_on_loans_taken_persists_interest_rate(): void
    {
        [$c, $fy] = $this->makeCompanyAndFy();

        Livewire::test(SectionPage::class,
            ['company' => 'a', 'fy' => '2025-26', 'section' => 'loans_taken'])
            ->callAction('createEntry', data: [
                'title' => 'HDFC car loan',
                'loan_amount' => 800000,
                'interest_rate' => '9.25% pa',
                'frequency' => 'one_time',
            ])
            ->assertHasNoActionErrors();

        $entry = Entry::where('title', 'HDFC car loan')->firstOrFail();
        $this->assertSame('9.25% pa', $entry->interest_rate);
        $this->assertSame(800000.0, (float) $entry->loan_amount);
        $this->assertSame('loans_taken', $entry->section->slug);
    }

    public function test_section_page_renders_loans_taken_with_kind_specific_labels(): void
    {
        [$c, $fy] = $this->makeCompanyAndFy();

        $response = $this->get("/admin/books/a/2025-26/section/loans_taken");
        $response->assertSuccessful();
        $response->assertSee('Loans Taken');
        $response->assertSee('Principal taken');
        $response->assertSee('Outstanding (we owe)');
    }
}
