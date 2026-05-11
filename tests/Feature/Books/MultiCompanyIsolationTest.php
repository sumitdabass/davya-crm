<?php

namespace Tests\Feature\Books;

use App\Models\Book\Company;
use App\Models\Book\Entry;
use App\Models\Book\FiscalYear;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class MultiCompanyIsolationTest extends TestCase
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

    public function test_does_not_leak_entries_from_one_company_into_another(): void
    {
        $a = Company::create(['name' => 'A', 'slug' => 'a']);
        $b = Company::create(['name' => 'B', 'slug' => 'b']);

        $fyA = FiscalYear::create([
            'company_id' => $a->id,
            'start_date' => '2025-04-01',
            'end_date' => '2026-03-31',
            'label' => '2025-26',
        ]);
        $fyB = FiscalYear::create([
            'company_id' => $b->id,
            'start_date' => '2025-04-01',
            'end_date' => '2026-03-31',
            'label' => '2025-26',
        ]);

        $sA = $a->sections()->where('slug', 'salary')->first();
        $sB = $b->sections()->where('slug', 'salary')->first();

        Entry::create([
            'company_id' => $a->id,
            'fiscal_year_id' => $fyA->id,
            'section_id' => $sA->id,
            'title' => 'Only-In-A',
        ]);
        Entry::create([
            'company_id' => $b->id,
            'fiscal_year_id' => $fyB->id,
            'section_id' => $sB->id,
            'title' => 'Only-In-B',
        ]);

        $this->get('/admin/books/a/2025-26/section/salary')
            ->assertSee('Only-In-A')
            ->assertDontSee('Only-In-B');
    }

    public function test_returns_404_when_fy_label_does_not_exist_for_that_company(): void
    {
        Company::create(['name' => 'A', 'slug' => 'a']);

        $this->get('/admin/books/a/9999-00')->assertNotFound();
    }
}
