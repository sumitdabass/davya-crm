<?php

namespace App\Observers;

use App\Models\Meeting;
use App\Models\Student;
use App\Services\ActivityDescriber;
use App\Services\Pipeline\PipelineConfig;
use App\Services\Pipeline\StageTransitionEngine;
use Illuminate\Support\Facades\Log;

class MeetingObserver
{
    public function __construct(
        private readonly StageTransitionEngine $engine,
        private readonly PipelineConfig $config,
        private readonly ActivityDescriber $describer,
    ) {
    }

    public function created(Meeting $meeting): void
    {
        $this->describer->meetingScheduled($meeting);

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

        if ($meeting->wasChanged('scheduled_at')) {
            $from = $meeting->getOriginal('scheduled_at');
            if ($from instanceof \DateTimeInterface) {
                $this->describer->meetingRescheduled($meeting, $from);
            }
        }

        if ($meeting->wasChanged('status') && $meeting->status === 'cancelled') {
            $this->describer->meetingCancelled($meeting);
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
        $target = $this->config->stageByName($newStage);
        if (! $target) {
            Log::warning('MeetingObserver: unknown target stage', [
                'student_id' => $student->id,
                'from' => $student->stage,
                'to' => $newStage,
            ]);
            return;
        }

        $out = $this->engine->forStageChange($student, $target->id);
        if (! empty($out['hard'])) {
            Log::warning('MeetingObserver: stage auto-advance blocked', [
                'student_id' => $student->id,
                'from' => $student->stage,
                'to' => $newStage,
                'errors' => $out['hard'],
            ]);
            return;
        }
        // Soft warnings are not logged from the observer — observer runs on infra events,
        // not user actions. Only user-facing entry points (Kanban drag, form save) show soft warnings.
        Student::withoutEvents(fn () => $student->update(['stage' => $newStage, 'stage_id' => $target->id]));
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
