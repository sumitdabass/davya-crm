<?php

namespace Tests\Feature;

use App\Filament\Pages\ReportsLanding;
use App\Models\User;
use App\Reports\ReportRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ReportsLandingTest extends TestCase
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

    public function test_admin_can_access_and_sees_all_four_cards(): void
    {
        $admin = $this->unblock(User::where('email', 'sumit@davya.local')->firstOrFail());

        $this->actingAs($admin);

        $cards = ReportRegistry::accessibleFor($admin);
        $this->assertCount(4, $cards);
        $this->assertSame(
            ['leads', 'payment', 'duplicate', 'activity'],
            array_map(fn ($c) => $c['key'], $cards),
        );

        $this->assertTrue(ReportsLanding::canAccess());

        Livewire::actingAs($admin)
            ->test(ReportsLanding::class)
            ->assertSee('Leads report')
            ->assertSee('Payment report')
            ->assertSee('Duplicate review')
            ->assertSee('Activity audit');
    }

    public function test_head_sees_only_payment_report(): void
    {
        $sonam = $this->unblock(User::where('email', 'sonam@davya.local')->firstOrFail());
        $this->actingAs($sonam);

        $cards = ReportRegistry::accessibleFor($sonam);
        $this->assertCount(1, $cards);
        $this->assertSame('payment', $cards[0]['key']);

        $this->assertTrue(ReportsLanding::canAccess());

        Livewire::actingAs($sonam)
            ->test(ReportsLanding::class)
            ->assertSee('Payment report')
            ->assertDontSee('Leads report')
            ->assertDontSee('Duplicate review')
            ->assertDontSee('Activity audit');
    }

    public function test_super_admin_without_admin_still_sees_leads_report(): void
    {
        // LeadsReport::canAccess allows admin OR super_admin. Verify the
        // super_admin-only path works.
        Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
        $nikhil = $this->unblock(User::where('email', 'nikhil@davya.local')->firstOrFail());
        $nikhil->syncRoles(['super_admin']); // strip head, keep only super_admin
        $this->actingAs($nikhil);

        $cards = ReportRegistry::accessibleFor($nikhil);
        $this->assertCount(1, $cards);
        $this->assertSame('leads', $cards[0]['key']);
    }

    public function test_member_cannot_access_landing(): void
    {
        $nisha = $this->unblock(User::where('email', 'nisha@davya.local')->firstOrFail());

        $this->assertFalse(ReportsLanding::canAccess());
        $this->assertSame([], ReportRegistry::accessibleFor($nisha));
    }

    public function test_freelancer_cannot_access_landing(): void
    {
        $kapil = $this->unblock(User::where('email', 'kapil@davya.local')->firstOrFail());
        $this->actingAs($kapil);

        $this->assertFalse(ReportsLanding::canAccess());
    }

    public function test_unauthenticated_cannot_access_landing(): void
    {
        $this->assertFalse(ReportsLanding::canAccess());
        $this->assertSame([], ReportRegistry::accessibleFor(null));
    }
}
