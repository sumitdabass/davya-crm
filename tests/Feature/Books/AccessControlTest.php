<?php

namespace Tests\Feature\Books;

use App\Models\Book\Company;
use App\Models\Book\FiscalYear;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AccessControlTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('books.enabled', true);
        foreach (['admin', 'head', 'member', 'freelancer', 'finance', 'super_admin'] as $r) {
            Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
        }
    }

    private function actingAsRole(string $role): User
    {
        $user = User::factory()->create([
            'is_active' => true,
            'must_change_password' => false,
        ]);
        $user->assignRole($role);
        $this->actingAs($user);

        return $user;
    }

    public function test_blocks_admin_role_from_books_urls(): void
    {
        $this->actingAsRole('admin');

        $this->get('/admin/books')->assertForbidden();
    }

    public function test_blocks_finance_role_from_books_urls(): void
    {
        $this->actingAsRole('finance');

        $this->get('/admin/books')->assertForbidden();
    }

    public function test_blocks_head_from_a_deep_books_url(): void
    {
        $this->actingAsRole('head');
        $c = Company::create(['name' => 'A', 'slug' => 'a']);
        FiscalYear::create([
            'company_id' => $c->id,
            'start_date' => '2025-04-01',
            'end_date' => '2026-03-31',
            'label' => '2025-26',
        ]);

        $this->get('/admin/books/a/2025-26')->assertForbidden();
    }

    public function test_allows_super_admin_everywhere(): void
    {
        $this->actingAsRole('super_admin');
        $c = Company::create(['name' => 'A', 'slug' => 'a']);
        FiscalYear::create([
            'company_id' => $c->id,
            'start_date' => '2025-04-01',
            'end_date' => '2026-03-31',
            'label' => '2025-26',
        ]);

        $this->get('/admin/books/a/2025-26')->assertSuccessful();
    }
}
