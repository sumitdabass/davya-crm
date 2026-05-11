<?php

namespace Tests\Feature\Books;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class FeatureFlagTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
    }

    /**
     * Create a user whose in-memory model has the `is_active` and
     * `must_change_password` attributes set. The migration defaults to
     * `is_active=1`, but the factory doesn't write those columns, so the
     * model returned by `actingAs` has them unset → Filament's panel
     * gate (`canAccessPanel`) reads NULL and 403s before our page runs.
     */
    private function makeUser(): User
    {
        return User::factory()->create([
            'is_active' => true,
            'must_change_password' => false,
        ]);
    }

    public function test_hides_books_module_when_feature_flag_is_off(): void
    {
        config()->set('books.enabled', false);

        $user = $this->makeUser();
        $user->assignRole('super_admin');

        $this->actingAs($user)->get('/admin/books')->assertNotFound();
    }

    public function test_serves_books_landing_for_super_admin_when_flag_is_on(): void
    {
        config()->set('books.enabled', true);

        $user = $this->makeUser();
        $user->assignRole('super_admin');

        $this->actingAs($user)->get('/admin/books')->assertSuccessful();
    }

    public function test_returns_403_to_non_super_admin_when_flag_is_on(): void
    {
        config()->set('books.enabled', true);

        $user = $this->makeUser();

        $this->actingAs($user)->get('/admin/books')->assertForbidden();
    }
}
