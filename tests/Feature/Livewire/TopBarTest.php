<?php

namespace Tests\Feature\Livewire;

use App\Livewire\TopBar;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TopBarTest extends TestCase
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

    public function test_renders_primary_tabs(): void
    {
        $admin = $this->unblock(User::where('email', 'sumit@davya.local')->first());

        Livewire::actingAs($admin)->test(TopBar::class)
            ->assertSee('Pipeline')
            ->assertSee('Students')
            ->assertSee('Today')
            ->assertSee('Reports')
            ->assertSee('Finance')
            ->assertSee('Jump to anything');
    }

    public function test_finance_tab_hidden_from_non_finance_role(): void
    {
        // counsellor role is not in the standard seed; create it for this test.
        Role::firstOrCreate(['name' => 'counsellor', 'guard_name' => 'web']);
        $counsellor = User::factory()->create();
        $counsellor->assignRole('counsellor');
        $this->unblock($counsellor);

        Livewire::actingAs($counsellor)->test(TopBar::class)
            ->assertDontSee('Finance');
    }

    public function test_head_reports_tab_routes_to_payment_report_not_leads_report(): void
    {
        // sonam is seeded as pure head (no admin). Before this regression test,
        // TopBar hardcoded Reports → /admin/leads-report which is admin-only,
        // so a head clicking Reports got a 403.
        $sonam = $this->unblock(User::where('email', 'sonam@davya.local')->first());

        Livewire::actingAs($sonam)->test(TopBar::class)
            ->assertSee('Reports')
            ->assertSee('/admin/payment-report')
            ->assertDontSee('/admin/leads-report');
    }

    public function test_member_and_freelancer_have_no_reports_tab(): void
    {
        $nisha = $this->unblock(User::where('email', 'nisha@davya.local')->first());
        $kapil = $this->unblock(User::where('email', 'kapil@davya.local')->first());

        Livewire::actingAs($nisha)->test(TopBar::class)
            ->assertSee('Pipeline')
            ->assertDontSee('Reports');

        Livewire::actingAs($kapil)->test(TopBar::class)
            ->assertSee('Pipeline')
            ->assertDontSee('Reports');
    }

    public function test_settings_gear_hidden_from_non_admins(): void
    {
        $sonam = $this->unblock(User::where('email', 'sonam@davya.local')->first());
        $nisha = $this->unblock(User::where('email', 'nisha@davya.local')->first());

        Livewire::actingAs($sonam)->test(TopBar::class)
            ->assertDontSee('title="Settings"');

        Livewire::actingAs($nisha)->test(TopBar::class)
            ->assertDontSee('title="Settings"');
    }

    public function test_admin_sees_settings_gear_and_user_menu_actions(): void
    {
        $admin = $this->unblock(User::where('email', 'sumit@davya.local')->first());

        Livewire::actingAs($admin)->test(TopBar::class)
            ->assertSee('title="Settings"', false)
            ->assertSee('Log out')
            ->assertSee('Install app')
            ->assertSee('Toggle dark mode')
            ->assertSee('Change password');
    }
}
