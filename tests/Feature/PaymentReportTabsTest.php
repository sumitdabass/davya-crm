<?php

namespace Tests\Feature;

use App\Filament\Pages\PaymentReport;
use App\Models\Payment;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class PaymentReportTabsTest extends TestCase
{
    use RefreshDatabase;

    private function unblock(User $u): User
    {
        $u->must_change_password = false;
        $u->save();
        return $u;
    }

    public function test_default_tab_is_report(): void
    {
        $this->seed();
        $sumit = $this->unblock(User::where('email', 'sumit@davya.local')->firstOrFail());
        $this->actingAs($sumit);

        Livewire::test(PaymentReport::class)
            ->assertSet('activeTab', 'report');
    }

    public function test_mount_respects_active_tab_query_param(): void
    {
        $this->seed();
        $sumit = $this->unblock(User::where('email', 'sumit@davya.local')->firstOrFail());
        $this->actingAs($sumit);

        Livewire::withQueryParams(['activeTab' => 'today'])
            ->test(PaymentReport::class)
            ->assertSet('activeTab', 'today');
    }

    public function test_mount_ignores_unknown_active_tab_query_param(): void
    {
        $this->seed();
        $sumit = $this->unblock(User::where('email', 'sumit@davya.local')->firstOrFail());
        $this->actingAs($sumit);

        Livewire::withQueryParams(['activeTab' => 'bogus'])
            ->test(PaymentReport::class)
            ->assertSet('activeTab', 'report');
    }

    public function test_can_switch_to_today_tab_and_it_shows_today_rows_only(): void
    {
        $this->seed();
        $sumit = $this->unblock(User::where('email', 'sumit@davya.local')->firstOrFail());
        $this->actingAs($sumit);

        $s = Student::create([
            'name' => 'Pay Student',
            'phone' => '9955000001',
            'course' => 'BBA',
            'stage' => 'Lead Captured',
            'owner_id' => $sumit->id,
            'lead_source' => 'Test',
        ]);
        Payment::create([
            'student_id' => $s->id, 'type' => 'advance', 'amount' => 250, 'mode' => 'upi',
            'received_at' => now('Asia/Kolkata'), 'recorded_by_user_id' => $sumit->id,
        ]);
        Payment::create([
            'student_id' => $s->id, 'type' => 'partial', 'amount' => 99, 'mode' => 'cash',
            'received_at' => now('Asia/Kolkata')->subDay(), 'recorded_by_user_id' => $sumit->id,
        ]);

        $rows = Livewire::test(PaymentReport::class)
            ->call('setTab', 'today')
            ->assertSet('activeTab', 'today')
            ->get('todayRows');

        $this->assertCount(1, $rows, 'today tab must show today rows only');
        $this->assertSame(250.0, (float) $rows[0]['amount']);
    }

    public function test_report_tab_still_returns_summary(): void
    {
        $this->seed();
        $sumit = $this->unblock(User::where('email', 'sumit@davya.local')->firstOrFail());
        $this->actingAs($sumit);

        $report = Livewire::test(PaymentReport::class)->instance()->getReport();

        $this->assertArrayHasKey('totals', $report);
        $this->assertArrayHasKey('byOwner', $report);
        $this->assertArrayHasKey('byType', $report);
    }

    public function test_today_csv_download_returns_streamed_response(): void
    {
        $this->seed();
        $sumit = $this->unblock(User::where('email', 'sumit@davya.local')->firstOrFail());
        $this->actingAs($sumit);

        $response = Livewire::test(PaymentReport::class)
            ->call('setTab', 'today')
            ->call('downloadTodayCsv');

        $this->assertNotNull($response);
    }
}
