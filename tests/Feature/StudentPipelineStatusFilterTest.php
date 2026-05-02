<?php

namespace Tests\Feature;

use App\Filament\Resources\StudentResource\Pages\ListStudents;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class StudentPipelineStatusFilterTest extends TestCase
{
    use RefreshDatabase;

    public function test_pipeline_status_filter_buckets_each_student_correctly(): void
    {
        $this->seed();
        $sumit = User::where('email', 'sumit@davya.local')->firstOrFail();
        $sumit->must_change_password = false;
        $sumit->save();
        $owner = User::where('email', 'sonam@davya.local')->firstOrFail();
        $this->actingAs($sumit);

        $fresh = Student::create([
            'phone' => '9100020001', 'name' => 'Fresh', 'owner_id' => $owner->id,
            'referrer_id' => $owner->id, 'lead_source' => 'X', 'stage' => 'Lead Captured',
        ]);
        $active = Student::create([
            'phone' => '9100020002', 'name' => 'Active', 'owner_id' => $owner->id,
            'referrer_id' => $owner->id, 'lead_source' => 'X', 'stage' => 'Meeting Scheduled',
        ]);
        $admittedSeat = Student::create([
            'phone' => '9100020003', 'name' => 'AdmittedSeat', 'owner_id' => $owner->id,
            'referrer_id' => $owner->id, 'lead_source' => 'X', 'stage' => 'Seat Allotted',
        ]);
        $admittedClosed = Student::create([
            'phone' => '9100020004', 'name' => 'AdmittedClosed', 'owner_id' => $owner->id,
            'referrer_id' => $owner->id, 'lead_source' => 'X', 'stage' => 'Closed',
            'close_reason' => 'Completed',
        ]);
        $closedLost = Student::create([
            'phone' => '9100020005', 'name' => 'ClosedLost', 'owner_id' => $owner->id,
            'referrer_id' => $owner->id, 'lead_source' => 'X', 'stage' => 'Closed',
            'close_reason' => 'Not Interested',
        ]);

        $assertOnly = function (string $bucket, array $expectedIds): void {
            Livewire::test(ListStudents::class)
                ->filterTable('pipeline_status', $bucket)
                ->assertCanSeeTableRecords(Student::whereIn('id', $expectedIds)->get())
                ->assertCanNotSeeTableRecords(Student::whereNotIn('id', $expectedIds)->get());
        };

        $assertOnly('past_capture', [$active->id, $admittedSeat->id, $admittedClosed->id, $closedLost->id]);
        $assertOnly('active', [$active->id]);
        $assertOnly('admitted', [$admittedSeat->id, $admittedClosed->id]);
        $assertOnly('closed_lost', [$closedLost->id]);
    }
}
