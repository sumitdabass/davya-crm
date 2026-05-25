<?php

namespace Tests\Feature;

use App\Filament\Resources\StudentResource\Pages\ListStudents;
use App\Models\RoundHistory;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StudentCsvExportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_csv_emits_admitted_data_from_latest_paid_round(): void
    {
        $admin = User::where('email', 'sumit@davya.local')->first();
        $this->actingAs($admin);

        $student = Student::create([
            'phone' => '9666000111',
            'name' => 'CSV Admit',
            'owner_id' => $admin->id,
            'lead_source' => 'Website',
            'stage' => 'Closed',
            'close_reason' => 'Completed',
            'deal_amount' => 100000,
        ]);

        RoundHistory::create([
            'student_id' => $student->id,
            'round_name' => 'Online_R1',
            'allotted_college' => 'IGDTUW',
            'allotted_course' => 'B.Tech CSE',
            'seat_fee_paid' => true,
            'fee_paid_at' => now()->setTime(10, 30),
            'outcome' => 'Allotted — Fee Paid',
        ]);

        $page = new ListStudents;
        $response = $page->exportCsv();

        ob_start();
        $response->sendContent();
        $csv = ob_get_clean();

        $this->assertStringContainsString('CSV Admit', $csv);
        $this->assertStringContainsString('IGDTUW', $csv,
            'Final College column must read from RoundHistory.allotted_college.');
        $this->assertStringContainsString('B.Tech CSE', $csv,
            'Final Course column must read from RoundHistory.allotted_course.');
        $this->assertStringContainsString(now()->format('Y-m-d'), $csv,
            'Admission Date column must read from RoundHistory.fee_paid_at.');
    }
}
