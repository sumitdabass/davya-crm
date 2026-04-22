<?php

namespace App\Observers;

use App\Models\Meeting;
use App\Models\Student;
use App\Services\StageTransitionValidator;
use Illuminate\Support\Facades\Log;

class MeetingObserver
{
    public function __construct(private readonly StageTransitionValidator $validator)
    {
    }

    public function created(Meeting $meeting): void
    {
        $student = $meeting->student()->first();
        if ($student === null) {
            return;
        }

        if ($student->stage === 'Lead Captured') {
            $this->advanceStage($student, 'Meeting Scheduled');
        }

        $this->syncMeetingDateCache($student);
    }

    public function updated(Meeting $meeting): void
    {
        if ($meeting->wasChanged('status') && $meeting->status === 'held' && $meeting->held_at === null) {
            Meeting::withoutEvents(fn () => $meeting->update(['held_at' => now()]));
        }

        $student = $meeting->student()->first();
        if ($student === null) {
            return;
        }

        if (
            $meeting->wasChanged('status')
            && $meeting->status === 'held'
            && $student->stage === 'Meeting Scheduled'
        ) {
            $this->advanceStage($student, 'Meeting Done');
        }

        $this->syncMeetingDateCache($student);
    }

    public function deleted(Meeting $meeting): void
    {
        $student = $meeting->student()->first();
        if ($student === null) {
            return;
        }
        $this->syncMeetingDateCache($student);
    }

    private function advanceStage(Student $student, string $newStage): void
    {
        $errors = $this->validator->forStageChange($student, $newStage);
        if (! empty($errors)) {
            Log::warning('MeetingObserver: stage auto-advance blocked', [
                'student_id' => $student->id,
                'from' => $student->stage,
                'to' => $newStage,
                'errors' => $errors,
            ]);
            return;
        }
        Student::withoutEvents(fn () => $student->update(['stage' => $newStage]));
    }

    private function syncMeetingDateCache(Student $student): void
    {
        $next = $student->meetings()
            ->where('status', 'scheduled')
            ->orderBy('scheduled_at')
            ->value('scheduled_at');

        if ((string) $student->meeting_date === (string) $next) {
            return;
        }

        Student::withoutEvents(fn () => $student->update(['meeting_date' => $next]));
    }
}
