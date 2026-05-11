<?php

namespace Tests\Feature\Books;

use App\Models\Book\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class HistoryPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_history_page_lists_recent_activity_for_super_admin(): void
    {
        config()->set('books.enabled', true);
        Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);

        $u = User::factory()->create([
            'is_active' => true,
            'must_change_password' => false,
        ]);
        $u->assignRole('super_admin');
        $this->actingAs($u);

        $c = Company::create(['name' => 'A', 'slug' => 'a']);
        $c->update(['name' => 'A (renamed)']);

        $this->get('/admin/books/history')
            ->assertSuccessful()
            ->assertSee('A (renamed)')
            ->assertSee('created')
            ->assertSee('updated');
    }

    public function test_history_page_returns_403_for_non_super_admin(): void
    {
        config()->set('books.enabled', true);
        Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);

        $u = User::factory()->create([
            'is_active' => true,
            'must_change_password' => false,
        ]);
        $this->actingAs($u);

        $this->get('/admin/books/history')->assertForbidden();
    }

    public function test_history_page_returns_404_when_books_disabled(): void
    {
        config()->set('books.enabled', false);
        Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);

        $u = User::factory()->create([
            'is_active' => true,
            'must_change_password' => false,
        ]);
        $u->assignRole('super_admin');
        $this->actingAs($u);

        $this->get('/admin/books/history')->assertNotFound();
    }
}
