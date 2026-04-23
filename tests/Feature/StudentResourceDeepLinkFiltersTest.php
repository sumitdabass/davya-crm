<?php

namespace Tests\Feature;

use App\Filament\Resources\StudentResource\Pages\ListStudents;
use App\Models\RoundHistory;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

class StudentResourceDeepLinkFiltersTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $this->seed();
        $sumit = User::where('email', 'sumit@davya.local')->first();
        $sumit->must_change_password = false;
        $sumit->save();
        return $sumit;
    }

    public function test_persistFiltersInSession_is_enabled_so_url_state_hydrates_filters(): void
    {
        // Bug 1 from prod smoke 2026-04-23: tableFilters[stuck][isActive]=1 URL
        // params were ignored on initial page load because Filament's
        // bootedInteractsWithTable() only fills the form from URL state when
        // session persistence is enabled. Without this guard, deep-link cards
        // land on the unfiltered list — defeating their purpose.
        $reflection = new \ReflectionClass(\App\Filament\Resources\StudentResource::class);
        $body = file_get_contents($reflection->getFileName());
        $this->assertStringContainsString('->persistFiltersInSession()', $body);
    }

    public function test_stuck_filter_returns_students_not_updated_for_14_days_and_not_closed(): void
    {
        $admin = $this->admin();

        $stuck = Student::factory()->create(['name' => 'Stuck', 'stage' => 'Lead Captured', 'owner_id' => $admin->id]);
        Student::withoutTimestamps(fn () => $stuck->update(['updated_at' => Carbon::now()->subDays(16)]));

        $fresh = Student::factory()->create(['name' => 'Fresh', 'stage' => 'Lead Captured', 'owner_id' => $admin->id]);

        $closed = Student::factory()->create(['name' => 'Closed', 'stage' => 'Closed', 'close_reason' => 'Not Interested', 'owner_id' => $admin->id]);
        Student::withoutTimestamps(fn () => $closed->update(['updated_at' => Carbon::now()->subDays(30)]));

        Livewire::actingAs($admin)
            ->test(ListStudents::class)
            ->set('tableFilters.stuck.isActive', true)
            ->assertStatus(200)
            ->assertCanSeeTableRecords([$stuck])
            ->assertCanNotSeeTableRecords([$fresh, $closed]);
    }

    public function test_seat_fee_pending_filter_returns_students_with_allotted_fee_pending_round_history(): void
    {
        $admin = $this->admin();

        $withPending = Student::factory()->create(['name' => 'Pending', 'owner_id' => $admin->id]);
        RoundHistory::create([
            'student_id' => $withPending->id,
            'round_name' => 'Online_R1',
            'outcome' => 'Allotted — Fee Pending',
            'seat_fee_paid' => false,
        ]);

        $withPaid = Student::factory()->create(['name' => 'Paid', 'owner_id' => $admin->id]);
        RoundHistory::create([
            'student_id' => $withPaid->id,
            'round_name' => 'Online_R1',
            'outcome' => 'Allotted — Fee Pending',
            'seat_fee_paid' => true,
        ]);

        $noHistory = Student::factory()->create(['name' => 'None', 'owner_id' => $admin->id]);

        Livewire::actingAs($admin)
            ->test(ListStudents::class)
            ->set('tableFilters.seat_fee_pending.isActive', true)
            ->assertStatus(200)
            ->assertCanSeeTableRecords([$withPending])
            ->assertCanNotSeeTableRecords([$withPaid, $noHistory]);
    }

    public function test_re_entry_filter_returns_students_whose_latest_round_is_kicked_out_fee_unpaid(): void
    {
        $admin = $this->admin();

        $kickedOut = Student::factory()->create(['name' => 'Kicked', 'owner_id' => $admin->id]);
        RoundHistory::create(['student_id' => $kickedOut->id, 'round_name' => 'Online_R1', 'outcome' => 'Allotted — Fee Pending', 'seat_fee_paid' => false]);
        RoundHistory::create(['student_id' => $kickedOut->id, 'round_name' => 'Online_R2', 'outcome' => 'Kicked Out — Fee Unpaid']);

        $latestIsAllotted = Student::factory()->create(['name' => 'Allotted', 'owner_id' => $admin->id]);
        RoundHistory::create(['student_id' => $latestIsAllotted->id, 'round_name' => 'Online_R1', 'outcome' => 'Kicked Out — Fee Unpaid']);
        RoundHistory::create(['student_id' => $latestIsAllotted->id, 'round_name' => 'Online_R2', 'outcome' => 'Allotted — Fee Pending', 'seat_fee_paid' => false]);

        $noHistory = Student::factory()->create(['name' => 'None', 'owner_id' => $admin->id]);

        Livewire::actingAs($admin)
            ->test(ListStudents::class)
            ->set('tableFilters.re_entry.isActive', true)
            ->assertStatus(200)
            ->assertCanSeeTableRecords([$kickedOut])
            ->assertCanNotSeeTableRecords([$latestIsAllotted, $noHistory]);
    }
}
