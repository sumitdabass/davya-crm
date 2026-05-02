<?php

namespace Tests\Feature\Performance;

use App\Filament\Pages\StaffPerformance;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class StaffPerformancePageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_admin_can_access_page(): void
    {
        $admin = User::where('email', 'sumit@davya.local')->firstOrFail();
        $admin->must_change_password = false;
        $admin->save();

        $this->actingAs($admin);

        $this->assertTrue(StaffPerformance::canAccess(), 'admin role should be allowed');
    }

    public function test_super_admin_only_user_can_access_page(): void
    {
        // A user with super_admin role but NOT the admin role must still pass.
        // Mirrors the SumitSuperAdminSeeder situation on prod.
        Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
        $u = User::factory()->create();
        $u->syncRoles(['super_admin']);
        $this->actingAs($u);

        $this->assertTrue(StaffPerformance::canAccess(), 'super_admin role should also be allowed');
    }

    public function test_non_admin_cannot_access_page(): void
    {
        $other = User::factory()->create();
        $this->actingAs($other);

        $this->assertFalse(StaffPerformance::canAccess(), 'non-admin must be denied');
    }

    public function test_page_renders_with_no_scores_yet(): void
    {
        $admin = User::where('email', 'sumit@davya.local')->firstOrFail();
        $admin->must_change_password = false;
        $admin->save();
        $this->actingAs($admin);

        Livewire::test(StaffPerformance::class)
            ->assertSuccessful()
            ->assertSee('Recalculate now')
            ->assertSee('Month');
    }

    public function test_month_options_returns_12_months_descending(): void
    {
        $page = new StaffPerformance;
        $opts = $page->getMonthOptions();

        $this->assertCount(12, $opts);
        $this->assertSame(now()->format('Y-m'), $opts[0]['value']);
        $this->assertSame(now()->subMonths(11)->format('Y-m'), $opts[11]['value']);
    }

    public function test_tier_color_mapping(): void
    {
        $page = new StaffPerformance;

        $this->assertStringContainsString('emerald', $page->tierColor('Star'));
        $this->assertStringContainsString('sky', $page->tierColor('Strong'));
        $this->assertStringContainsString('slate', $page->tierColor('Solid'));
        $this->assertStringContainsString('amber', $page->tierColor('Growth'));
        $this->assertStringContainsString('rose', $page->tierColor('Coaching'));
    }
}
