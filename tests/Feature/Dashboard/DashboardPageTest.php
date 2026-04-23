<?php

namespace Tests\Feature\Dashboard;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function unblock(User $u): User
    {
        $u->must_change_password = false;
        $u->save();
        return $u;
    }

    public function test_dashboard_renders_default_cards_for_new_user(): void
    {
        $admin = $this->unblock(User::where('email', 'sumit@davya.local')->first());

        $response = $this->actingAs($admin)->get('/admin');

        $response->assertOk();
        $response->assertSee('Stuck Leads');
        $response->assertSee('Re-Entry Candidates');
        $response->assertSee('Seat Fee Pending');
    }

    public function test_dashboard_honors_saved_user_prefs(): void
    {
        $admin = $this->unblock(User::where('email', 'sumit@davya.local')->first());
        $admin->dashboard_prefs = ['dashboard' => ['enabled' => ['stuck_leads']]];
        $admin->save();

        $response = $this->actingAs($admin)->get('/admin');

        $response->assertOk();
        $response->assertSee('Stuck Leads');
    }
}
