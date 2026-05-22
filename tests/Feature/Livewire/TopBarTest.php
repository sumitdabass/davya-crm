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
            ->assertSee('Search students by name');
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

    public function test_reports_tab_points_to_landing_for_any_report_access(): void
    {
        // Reports tab now always routes to /admin/reports (the landing).
        // The landing filters cards by each viewer's canAccess gates.
        $admin = $this->unblock(User::where('email', 'sumit@davya.local')->first());
        Livewire::actingAs($admin)->test(TopBar::class)
            ->assertSee('Reports')
            ->assertSee('/admin/reports');

        // Heads still see the tab (PaymentReport::canAccess allows head) — they
        // land on the same /admin/reports URL but the page renders only the
        // Payment Report card for them.
        $sonam = $this->unblock(User::where('email', 'sonam@davya.local')->first());
        Livewire::actingAs($sonam)->test(TopBar::class)
            ->assertSee('Reports')
            ->assertSee('/admin/reports');

        // Members and freelancers can't access any report — no tab.
        $nisha = $this->unblock(User::where('email', 'nisha@davya.local')->first());
        $kapil = $this->unblock(User::where('email', 'kapil@davya.local')->first());

        foreach ([$nisha, $kapil] as $nonAccess) {
            Livewire::actingAs($nonAccess)->test(TopBar::class)
                ->assertSee('Pipeline')
                ->assertDontSee('Reports');
        }
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

    public function test_books_tab_visible_for_super_admin_when_flag_on(): void
    {
        config()->set('books.enabled', true);
        Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
        $admin = $this->unblock(User::where('email', 'sumit@davya.local')->first());
        $admin->assignRole('super_admin');

        Livewire::actingAs($admin)->test(TopBar::class)
            ->assertSee('Books')
            ->assertSee('/admin/books');
    }

    public function test_books_tab_hidden_when_flag_off(): void
    {
        config()->set('books.enabled', false);
        Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
        $admin = $this->unblock(User::where('email', 'sumit@davya.local')->first());
        $admin->assignRole('super_admin');

        Livewire::actingAs($admin)->test(TopBar::class)
            ->assertDontSee('>Books<');
    }

    public function test_books_tab_hidden_from_non_super_admin(): void
    {
        config()->set('books.enabled', true);
        $sonam = $this->unblock(User::where('email', 'sonam@davya.local')->first()); // head, not super_admin

        Livewire::actingAs($sonam)->test(TopBar::class)
            ->assertDontSee('>Books<');
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
