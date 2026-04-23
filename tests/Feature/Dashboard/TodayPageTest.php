<?php

namespace Tests\Feature\Dashboard;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TodayPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function unblock(User $user): User
    {
        $user->must_change_password = false;
        $user->save();
        return $user;
    }

    public function test_today_page_renders_default_cards_for_new_user(): void
    {
        $admin = $this->unblock(User::where('email', 'sumit@davya.local')->first());
        $response = $this->actingAs($admin)->get('/admin/today');

        $response->assertOk();
        $response->assertSee('Today Meetings');
        $response->assertSee('Today Payments');
        $response->assertSee('Meetings Held Today');
        $response->assertSee('Leads Captured Today');
        $response->assertSee('Admissions Closed Today');
    }

    public function test_today_page_honors_saved_user_prefs_order(): void
    {
        $admin = $this->unblock(User::where('email', 'sumit@davya.local')->first());
        $admin->dashboard_prefs = [
            'today' => ['enabled' => ['leads_captured_today', 'today_meetings']],
        ];
        $admin->save();

        $response = $this->actingAs($admin)->get('/admin/today');
        $response->assertOk();

        $body = $response->getContent();
        $leadsPos = strpos($body, 'Leads Captured Today');
        $meetingsPos = strpos($body, 'Today Meetings');
        $this->assertNotFalse($leadsPos);
        $this->assertNotFalse($meetingsPos);
        $this->assertLessThan($meetingsPos, $leadsPos, 'Leads Captured should render before Today Meetings');
    }

    public function test_empty_prefs_array_renders_empty_state_then_auto_append_hydrates_default_cards(): void
    {
        $admin = $this->unblock(User::where('email', 'sumit@davya.local')->first());
        $admin->dashboard_prefs = ['today' => ['enabled' => []]];
        $admin->save();

        $response = $this->actingAs($admin)->get('/admin/today');
        $response->assertOk();
        // Resolver auto-appends defaults, so cards should be visible — no empty state.
        $response->assertSee('Today Meetings');
    }

    public function test_saved_array_with_only_unknown_ids_auto_appends_defaults(): void
    {
        $admin = $this->unblock(User::where('email', 'sumit@davya.local')->first());
        $admin->dashboard_prefs = ['today' => ['enabled' => ['_unknown_id_']]];
        $admin->save();

        $response = $this->actingAs($admin)->get('/admin/today');
        $response->assertOk();
        // Unknown id dropped, defaults auto-append.
        $response->assertSee('Today Meetings');
    }
}
