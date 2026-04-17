<?php

namespace Tests\Feature;

use App\Filament\Resources\StudentResource\Pages\ListStudents;
use App\Models\Payment;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StudentsCsvExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_exportCsv_streams_rows_for_admin_visibility(): void
    {
        $this->seed();

        $sumit  = User::where('email', 'sumit@davya.local')->first();
        $nikhil = User::where('email', 'nikhil@davya.local')->first();
        $nisha  = User::where('email', 'nisha@davya.local')->first();

        $a = Student::create([
            'phone' => '9100001234', 'name' => 'Aarav',
            'owner_id' => $nikhil->id, 'referrer_id' => $nikhil->id, 'lead_source' => 'Nikhil',
            'stage' => 'Onboarded', 'deal_amount' => 100000,
        ]);
        Payment::create([
            'student_id' => $a->id, 'type' => 'advance', 'amount' => 25000,
            'received_at' => now(), 'recorded_by_user_id' => $nikhil->id,
        ]);
        Student::create([
            'phone' => '9100005678', 'name' => 'Bhavya',
            'owner_id' => $nisha->id, 'referrer_id' => $nisha->id, 'lead_source' => 'Nisha',
            'stage' => 'Lead Captured',
        ]);

        $this->actingAs($sumit);

        $page = new ListStudents;
        $response = $page->exportCsv();

        ob_start();
        $response->sendContent();
        $body = ob_get_clean();

        $this->assertStringStartsWith('ID,Phone,Name,', $body);
        $this->assertStringContainsString('9100001234', $body);
        $this->assertStringContainsString('Aarav', $body);
        $this->assertStringContainsString('Nikhil', $body); // owner column
        $this->assertStringContainsString('Onboarded', $body);
        $this->assertStringContainsString('25000', $body);
        $this->assertStringContainsString('Bhavya', $body);
    }
}
