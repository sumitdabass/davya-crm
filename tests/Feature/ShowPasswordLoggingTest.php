<?php

namespace Tests\Feature;

use App\Actions\RevealIpuPassword;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class ShowPasswordLoggingTest extends TestCase
{
    use RefreshDatabase;

    public function test_revealing_ipu_password_writes_activity_log_entry(): void
    {
        $this->seed();
        $sumit = User::where('email', 'sumit@davya.local')->first();
        $student = Student::create([
            'phone' => '9888888888',
            'name' => 'PwTest',
            'owner_id' => $sumit->id,
            'referrer_id' => $sumit->id,
            'lead_source' => 'Sumit',
            'ipu_password' => 'secret',
        ]);

        $this->actingAs($sumit);
        $revealed = (new RevealIpuPassword)($student);
        $this->assertEquals('secret', $revealed);

        $activity = Activity::where('event', 'ipu_password_revealed')->latest()->first();
        $this->assertNotNull($activity);
        $this->assertEquals($sumit->id, $activity->causer_id);
        $this->assertEquals($student->id, $activity->subject_id);
    }
}
