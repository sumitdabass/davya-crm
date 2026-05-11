<?php

namespace Tests\Feature\Books;

use App\Filament\Pages\Book\CompanyDashboard;
use App\Models\Book\Company;
use App\Models\Book\FiscalYear;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DashboardCustomizationTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('books.enabled', true);
        Role::firstOrCreate(['name' => 'super_admin']);
        $u = User::factory()->create(['is_active' => true, 'must_change_password' => false]);
        $u->assignRole('super_admin');
        $this->actingAs($u);
        $this->user = $u;
    }

    public function test_all_regions_visible_by_default(): void
    {
        $c = Company::create(['name' => 'A', 'slug' => 'a']);
        $fy = FiscalYear::create(['company_id' => $c->id, 'start_date' => '2025-04-01',
            'end_date' => '2026-03-31', 'label' => '2025-26']);
        $this->get('/admin/books/a/2025-26')
            ->assertSee('Total Income')
            ->assertSee('Customize');
    }

    public function test_customize_action_persists_prefs(): void
    {
        $c = Company::create(['name' => 'A', 'slug' => 'a']);
        $fy = FiscalYear::create(['company_id' => $c->id, 'start_date' => '2025-04-01',
            'end_date' => '2026-03-31', 'label' => '2025-26']);

        Livewire::test(CompanyDashboard::class, ['company' => 'a', 'fy' => '2025-26'])
            ->callAction('customize', [
                'kpis' => false, 'rollups' => true, 'assets' => true, 'loans' => true,
            ])
            ->assertHasNoActionErrors();

        $this->assertSame([
            'kpis' => false, 'rollups' => true, 'assets' => true, 'loans' => true,
        ], $this->user->fresh()->books_dashboard_prefs);
    }

    public function test_hidden_region_is_not_rendered(): void
    {
        $this->user->forceFill(['books_dashboard_prefs' => [
            'kpis' => false, 'rollups' => true, 'assets' => true, 'loans' => true,
        ]])->save();

        $c = Company::create(['name' => 'A', 'slug' => 'a']);
        $fy = FiscalYear::create(['company_id' => $c->id, 'start_date' => '2025-04-01',
            'end_date' => '2026-03-31', 'label' => '2025-26']);

        $this->get('/admin/books/a/2025-26')
            ->assertDontSee('Total Income')
            // section rollup cards (defaults exist via CompanyObserver) — should still show their name
            ->assertSee('Salary');
    }
}
