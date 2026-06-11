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
        // List cards now render as checklist sections (SectionRegistry labels).
        $response->assertSee('Meetings today');
        $response->assertSee('Received today');
        // Stat cards render in the stats strip with their card label beneath the value.
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
        // Stat strip (with the 'Leads Captured Today' label) renders above the
        // 'Meetings today' checklist section.
        $leadsPos = strpos($body, 'Leads Captured Today');
        $meetingsPos = strpos($body, 'Meetings today');
        $this->assertNotFalse($leadsPos);
        $this->assertNotFalse($meetingsPos);
        $this->assertLessThan($meetingsPos, $leadsPos, 'Leads Captured should render before Meetings today');
    }

    public function test_empty_prefs_array_renders_empty_state_with_reset_link(): void
    {
        $admin = $this->unblock(User::where('email', 'sumit@davya.local')->first());
        $admin->dashboard_prefs = ['today' => ['enabled' => []]];
        $admin->save();

        $response = $this->actingAs($admin)->get('/admin/today');
        $response->assertOk();
        $response->assertDontSee('Meetings today');
        $response->assertSee('hidden all cards');
        $response->assertSee('Reset to defaults');
    }

    public function test_saved_array_with_only_unknown_ids_auto_appends_defaults(): void
    {
        $admin = $this->unblock(User::where('email', 'sumit@davya.local')->first());
        $admin->dashboard_prefs = ['today' => ['enabled' => ['_unknown_id_']]];
        $admin->save();

        $response = $this->actingAs($admin)->get('/admin/today');
        $response->assertOk();
        // Unknown id dropped, defaults auto-append (rendered as a section).
        $response->assertSee('Meetings today');
    }
}
