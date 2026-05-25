<?php

namespace Tests\Feature\Books;

use App\Filament\Pages\Book\CompanyDashboard;
use App\Models\Book\Company;
use App\Models\Book\Entry;
use App\Models\Book\EntryPayment;
use App\Models\Book\FiscalYear;
use App\Models\Book\IncomeEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CompanyDashboardPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('books.enabled', true);
        Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);

        $user = User::factory()->create([
            'is_active' => true,
            'must_change_password' => false,
        ]);
        $user->assignRole('super_admin');
        $this->actingAs($user);
    }

    public function test_renders_the_dashboard_with_kpi_numbers(): void
    {
        $c = Company::create(['name' => 'A', 'slug' => 'a']);
        $fy = FiscalYear::create([
            'company_id' => $c->id,
            'start_date' => '2025-04-01',
            'end_date' => '2026-03-31',
            'label' => '2025-26',
        ]);
        IncomeEntry::create([
            'company_id' => $c->id,
            'fiscal_year_id' => $fy->id,
            'occurred_on' => '2025-05-01',
            'source' => 'X',
            'amount' => 1000000,
        ]);
        $s = $c->sections()->where('slug', 'salary')->first();
        $e = Entry::create([
            'company_id' => $c->id,
            'fiscal_year_id' => $fy->id,
            'section_id' => $s->id,
            'title' => 'Usha',
            'salary_amount' => 100000,
        ]);
        EntryPayment::create([
            'entry_id' => $e->id,
            'amount' => 50000,
            'direction' => 'out',
            'mode' => 'bank',
            'occurred_on' => '2025-05-15',
        ]);

        $response = $this->get("/admin/books/{$c->slug}/{$fy->label}");
        $response->assertSuccessful();
        $response->assertSee('1,000,000.00');
        $response->assertSee('50,000.00');
    }

    public function test_badges_carryover_as_estimate_when_prior_fy_is_open(): void
    {
        $c = Company::create(['name' => 'A', 'slug' => 'a']);
        FiscalYear::create([
            'company_id' => $c->id,
            'start_date' => '2024-04-01',
            'end_date' => '2025-03-31',
            'label' => '2024-25',
        ]);
        $fy = FiscalYear::create([
            'company_id' => $c->id,
            'start_date' => '2025-04-01',
            'end_date' => '2026-03-31',
            'label' => '2025-26',
        ]);

        $this->get("/admin/books/{$c->slug}/{$fy->label}")
            ->assertSee('estimate');
    }

    public function test_company_fiscal_years_helper_returns_all_fys_newest_first(): void
    {
        $c = Company::create(['name' => 'X', 'slug' => 'x']);
        $fy1 = FiscalYear::create(['company_id' => $c->id, 'start_date' => '2024-04-01',
            'end_date' => '2025-03-31', 'label' => '2024-25']);
        $fy2 = FiscalYear::create(['company_id' => $c->id, 'start_date' => '2025-04-01',
            'end_date' => '2026-03-31', 'label' => '2025-26']);
        $fy3 = FiscalYear::create(['company_id' => $c->id, 'start_date' => '2026-04-01',
            'end_date' => '2027-03-31', 'label' => '2026-27']);

        $page = Livewire::test(CompanyDashboard::class,
            ['company' => 'x', 'fy' => '2025-26'])->instance();

        $labels = $page->companyFiscalYears()->pluck('label')->all();
        $this->assertSame(['2026-27', '2025-26', '2024-25'], $labels);
    }

    public function test_dashboard_renders_year_switcher_when_multiple_fys_exist(): void
    {
        $c = Company::create(['name' => 'Y', 'slug' => 'y']);
        FiscalYear::create(['company_id' => $c->id, 'start_date' => '2024-04-01',
            'end_date' => '2025-03-31', 'label' => '2024-25']);
        $fy2 = FiscalYear::create(['company_id' => $c->id, 'start_date' => '2025-04-01',
            'end_date' => '2026-03-31', 'label' => '2025-26']);

        $this->get("/admin/books/{$c->slug}/{$fy2->label}")
            ->assertSee('davya-fy-switcher', false)
            ->assertSee('2024-25')
            ->assertSee('2025-26');
    }

    public function test_dashboard_omits_switcher_when_only_one_fy(): void
    {
        $c = Company::create(['name' => 'Z', 'slug' => 'z']);
        $fy = FiscalYear::create(['company_id' => $c->id, 'start_date' => '2025-04-01',
            'end_date' => '2026-03-31', 'label' => '2025-26']);

        $this->get("/admin/books/{$c->slug}/{$fy->label}")
            ->assertDontSee('davya-fy-switcher', false);
    }

    public function test_balance_available_equals_income_plus_loans_taken_minus_expense(): void
    {
        $c = Company::create(['name' => 'B', 'slug' => 'b']);
        $fy = FiscalYear::create(['company_id' => $c->id, 'start_date' => '2025-04-01',
            'end_date' => '2026-03-31', 'label' => '2025-26']);

        // Income: ₹10,00,000
        IncomeEntry::create(['company_id' => $c->id, 'fiscal_year_id' => $fy->id,
            'occurred_on' => '2025-05-01', 'source' => 'Sales', 'amount' => 1000000]);

        // Loan taken (principal received): ₹2,00,000
        $loansTaken = $c->sections()->where('slug', 'loans_taken')->first();
        Entry::create(['company_id' => $c->id, 'fiscal_year_id' => $fy->id,
            'section_id' => $loansTaken->id, 'title' => 'HDFC OD', 'loan_amount' => 200000]);

        // Expense (cash outflow on salary): ₹50,000
        $salary = $c->sections()->where('slug', 'salary')->first();
        $emp = Entry::create(['company_id' => $c->id, 'fiscal_year_id' => $fy->id,
            'section_id' => $salary->id, 'title' => 'Usha', 'salary_amount' => 100000]);
        EntryPayment::create(['entry_id' => $emp->id, 'amount' => 50000, 'direction' => 'out',
            'mode' => 'bank', 'occurred_on' => '2025-05-15']);

        $page = Livewire::test(CompanyDashboard::class,
            ['company' => 'b', 'fy' => '2025-26'])->instance();
        $kpis = $page->getKpis();

        // 10,00,000 + 2,00,000 − 50,000 = 11,50,000
        $this->assertEqualsWithDelta(1150000.0, $kpis['balance_available'], 0.01,
            'Balance Available = Income + Loan Taken − Expense');
        $this->assertEqualsWithDelta(200000.0, $kpis['loan_taken_principal'], 0.01);

        $this->get("/admin/books/{$c->slug}/{$fy->label}")
            ->assertSee('Balance Available')
            // Hero number splits the integer and decimals across spans, so
            // assert the integer part appears in the rendered HTML.
            ->assertSee('1,150,000');
    }
}
