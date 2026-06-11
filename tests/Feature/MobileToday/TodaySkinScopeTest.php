<?php

namespace Tests\Feature\MobileToday;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TodaySkinScopeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function admin(): User
    {
        // Seeded admin (Spatie `admin` role); no `role` column on users.
        // Clear must_change_password so the panel doesn't 302 to the change-pw screen.
        $u = User::where('email', 'sumit@davya.local')->first();
        $u->update(['must_change_password' => false]);

        return $u;
    }

    public function test_skin_loads_on_today_and_not_elsewhere(): void
    {
        $user = $this->admin();

        $today = $this->actingAs($user)->get('/admin/today');
        $today->assertOk();
        $today->assertSee('today-skin.css', false);
        $today->assertSee('davya-today-skin', false);

        $students = $this->actingAs($user)->get('/admin/students');
        $students->assertOk();
        $students->assertDontSee('today-skin.css', false);
        $students->assertDontSee('davya-today-skin', false);
    }
}
