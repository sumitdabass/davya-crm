<?php

namespace Tests\Feature;

use App\Filament\Pages\LeadsReport;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class LeadsReportPageTest extends TestCase
{
    use RefreshDatabase;

    private function unblock(User $u): User
    {
        $u->must_change_password = false;
        $u->save();
        return $u;
    }

    public function test_admin_can_access_and_sees_names(): void
    {
        $this->seed();
        $sumit = $this->unblock(User::where('email', 'sumit@davya.local')->firstOrFail());
        $sonam = User::where('email', 'sonam@davya.local')->firstOrFail();

        Student::create([
            'phone' => '9100005001', 'name' => 'Past',
            'owner_id' => $sonam->id, 'referrer_id' => $sonam->id,
            'lead_source' => 'Sonam', 'stage' => 'Meeting Scheduled',
            'preference_r1' => 'Some College',
        ]);
        Student::create([
            'phone' => '9100005002', 'name' => 'Fresh',
            'owner_id' => $sonam->id, 'referrer_id' => $sonam->id,
            'lead_source' => 'Sonam', 'stage' => 'Lead Captured',
            'preference_r1' => 'Some College',
        ]);

        $this->actingAs($sumit);

        Livewire::test(LeadsReport::class)
            ->assertStatus(200)
            ->assertSee('Sonam')
            ->assertSee('By owner')
            ->assertSee('By referrer');
    }

    public function test_non_admin_cannot_access(): void
    {
        $this->seed();
        $nisha = $this->unblock(User::where('email', 'nisha@davya.local')->firstOrFail());
        $this->actingAs($nisha);

        $this->assertFalse(LeadsReport::canAccess());
    }

    public function test_head_also_blocked_unlike_payment_report(): void
    {
        // LeadsReport is admin-only on purpose (PaymentReport allows head too).
        $this->seed();
        $sonam = $this->unblock(User::where('email', 'sonam@davya.local')->firstOrFail());
        $this->assertTrue($sonam->hasRole('head'));
        $this->actingAs($sonam);

        $this->assertFalse(LeadsReport::canAccess());
    }

    public function test_mount_hydrates_performance_month_from_query_param(): void
    {
        $this->seed();
        $sumit = $this->unblock(User::where('email', 'sumit@davya.local')->firstOrFail());
        $this->actingAs($sumit);

        Livewire::withQueryParams(['month' => '2026-03'])
            ->test(LeadsReport::class)
            ->assertSet('performanceMonth', '2026-03');
    }

    public function test_mount_rejects_invalid_month_format(): void
    {
        $this->seed();
        $sumit = $this->unblock(User::where('email', 'sumit@davya.local')->firstOrFail());
        $this->actingAs($sumit);

        Livewire::withQueryParams(['month' => 'bogus'])
            ->test(LeadsReport::class)
            ->assertSet('performanceMonth', now()->format('Y-m'));

        Livewire::withQueryParams(['month' => '2026-13'])
            ->test(LeadsReport::class)
            ->assertSet('performanceMonth', now()->format('Y-m'));
    }
}
