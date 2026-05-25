<?php

namespace Tests\Feature\Books;

use App\Filament\Pages\Book\CompaniesLanding;
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

class CompaniesLandingPageTest extends TestCase
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

    public function test_lists_companies(): void
    {
        Company::create(['name' => 'Davyas', 'slug' => 'davyas']);
        Company::create(['name' => 'Spillin Beans', 'slug' => 'spillin-beans']);

        $this->get('/admin/books')
            ->assertSuccessful()
            ->assertSee('Davyas')
            ->assertSee('Spillin Beans');
    }

    public function test_creates_a_company_via_livewire_action(): void
    {
        Livewire::test(CompaniesLanding::class)
            ->callAction('createCompany', ['name' => 'Kyne', 'slug' => 'kyne'])
            ->assertHasNoActionErrors();

        $this->assertTrue(Company::where('slug', 'kyne')->exists());
    }

    public function test_landing_card_shows_balance_available_for_companies_latest_fy(): void
    {
        $c = Company::create(['name' => 'EDU', 'slug' => 'edu']);
        $fy = FiscalYear::create(['company_id' => $c->id, 'start_date' => '2025-04-01',
            'end_date' => '2026-03-31', 'label' => '2025-26']);

        IncomeEntry::create(['company_id' => $c->id, 'fiscal_year_id' => $fy->id,
            'occurred_on' => '2025-05-01', 'source' => 'Sales', 'amount' => 1000000]);

        $loansTaken = $c->sections()->where('slug', 'loans_taken')->first();
        Entry::create(['company_id' => $c->id, 'fiscal_year_id' => $fy->id,
            'section_id' => $loansTaken->id, 'title' => 'HDFC OD', 'loan_amount' => 200000]);

        $salary = $c->sections()->where('slug', 'salary')->first();
        $emp = Entry::create(['company_id' => $c->id, 'fiscal_year_id' => $fy->id,
            'section_id' => $salary->id, 'title' => 'Usha', 'salary_amount' => 100000]);
        EntryPayment::create(['entry_id' => $emp->id, 'amount' => 50000, 'direction' => 'out',
            'mode' => 'bank', 'occurred_on' => '2025-05-15']);

        // 10,00,000 + 2,00,000 − 50,000 = 11,50,000
        $this->get('/admin/books')
            ->assertSuccessful()
            ->assertSee('EDU')
            ->assertSee('Balance Available')
            ->assertSee('1,150,000');
    }
}
