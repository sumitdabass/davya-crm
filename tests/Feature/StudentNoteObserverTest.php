<?php

namespace Tests\Feature;

use App\Models\Student;
use App\Models\StudentNote;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class StudentNoteObserverTest extends TestCase
{
    use RefreshDatabase;

    public function test_note_created_logs(): void
    {
        $this->seed();
        $sumit = User::where('email', 'sumit@davya.local')->first();
        $this->actingAs($sumit);
        $s = Student::create([
            'phone' => '9999960001', 'name' => 'T', 'owner_id' => $sumit->id,
            'lead_source' => 'Website', 'stage' => 'Lead Captured',
        ]);
        Activity::query()->delete();

        StudentNote::create([
            'student_id' => $s->id,
            'author_id' => $sumit->id,
            'body' => 'Parent called, wants R3',
        ]);

        $a = Activity::where('subject_id', $s->id)->latest('id')->first();
        $this->assertSame('note_added', $a->event);
        $this->assertStringContainsString('Parent called', $a->description);
    }
}
