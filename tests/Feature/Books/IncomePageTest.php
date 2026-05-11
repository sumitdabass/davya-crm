<?php

namespace Tests\Feature\Books;

use App\Filament\Pages\Book\IncomePage;
use App\Models\Book\Company;
use App\Models\Book\FiscalYear;
use App\Models\Book\IncomeEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class IncomePageTest extends TestCase
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

    public function test_lists_income_entries(): void
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
            'occurred_on' => '2025-05-15',
            'source' => 'Client A',
            'amount' => 500000,
        ]);

        $this->get("/admin/books/{$c->slug}/{$fy->label}/income")
            ->assertSuccessful()
            ->assertSee('Client A')
            ->assertSee('500,000');
    }

    public function test_creates_income_via_action(): void
    {
        $c = Company::create(['name' => 'A', 'slug' => 'a']);
        $fy = FiscalYear::create([
            'company_id' => $c->id,
            'start_date' => '2025-04-01',
            'end_date' => '2026-03-31',
            'label' => '2025-26',
        ]);

        Livewire::test(IncomePage::class, [
            'company' => 'a',
            'fy' => '2025-26',
        ])
            ->callAction('createIncome', [
                'occurred_on' => '2025-06-01',
                'source' => 'Y',
                'amount' => 250000,
            ])
            ->assertHasNoActionErrors();

        $this->assertTrue(IncomeEntry::where('source', 'Y')->exists());
    }
}
