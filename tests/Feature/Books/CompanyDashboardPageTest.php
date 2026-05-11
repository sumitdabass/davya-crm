<?php

namespace Tests\Feature\Books;

use App\Models\Book\Company;
use App\Models\Book\Entry;
use App\Models\Book\EntryPayment;
use App\Models\Book\FiscalYear;
use App\Models\Book\IncomeEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
