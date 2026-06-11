<?php

namespace Tests\Feature\MobileToday;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TodayCustomizeParityTest extends TestCase
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
        // Clear must_change_password so the panel doesn't 302 to change-pw.
        $u = User::where('email', 'sumit@davya.local')->first();
        $u->update(['must_change_password' => false]);

        return $u;
    }

    public function test_hiding_cards_via_prefs_removes_their_sections(): void
    {
        $user = $this->admin();

        // Sanity: the stuck_leads section renders by default (default-on for `today`).
        $this->actingAs($user)->get('/admin/today')->assertSee('Stuck leads', false);

        // Hide via the EXACT prefs write path CustomizeCardsModal::save() uses:
        // a JSON `dashboard_prefs` column on users keyed by surface with an
        // ordered `enabled` list. An empty list is the resolver-honored
        // "uncheck all" — UserPrefsResolver returns [] (no re-appended defaults),
        // so no sections render. (There is NO DashboardCardPref model/table;
        // prefs live on users.dashboard_prefs.)
        $prefs = $user->dashboard_prefs ?? [];
        $prefs['today'] = ['enabled' => []];
        $user->dashboard_prefs = $prefs;
        $user->save();

        // The section is gone, and the page still renders fine (empty state).
        $res = $this->actingAs($user)->get('/admin/today');
        $res->assertOk();
        $res->assertDontSee('Stuck leads', false);
    }
}
