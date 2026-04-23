<?php

namespace App\Services;

use App\Support\RoundNameToStage;
use App\Models\Meeting;
use App\Models\Payment;
use App\Models\RoundHistory;
use App\Models\Student;
use App\Models\StudentNote;
use App\Models\User;
use Illuminate\Support\Str;

class ActivityDescriber
{
    public function stageChanged(Student $s, string $from, string $to): void
    {
        $this->log($s, 'stage_changed', "Moved from {$from} → {$to}");
    }

    public function ownerChanged(Student $s, ?User $from, User $to): void
    {
        $fromName = $from?->name ?? '—';
        $this->log($s, 'owner_changed', "Reassigned owner {$fromName} → {$to->name}");
    }

    public function ipuCodeChanged(Student $s, bool $wasSet): void
    {
        $desc = $wasSet ? 'Updated IPU login code' : 'Set IPU login code';
        $this->log($s, 'ipu_code_changed', $desc);
    }

    public function closed(Student $s, string $reason): void
    {
        $this->log($s, 'closed', "Closed (reason: {$reason})");
    }

    public function reopened(Student $s, string $reason): void
    {
        $this->log($s, 'reopened', "Re-opened (reason: {$reason})");
    }

    public function paymentAdded(Payment $p): void
    {
        $parts = ['Added payment ₹' . number_format((float) $p->amount, 0, '.', ',')];
        if (! empty($p->type)) {
            $parts[] = "({$p->type})";
        }
        if (! empty($p->proof_url)) {
            $parts[] = '· proof uploaded';
        }
        $this->log($p->student, 'payment_added', implode(' ', $parts));
    }

    public function paymentUpdated(Payment $p): void
    {
        $this->log($p->student, 'payment_updated', "Updated payment #{$p->id}");
    }

    public function paymentDeleted(Payment $p, Student $student): void
    {
        $amount = number_format((float) $p->amount, 0, '.', ',');
        $this->log($student, 'payment_deleted', "Deleted payment #{$p->id} (₹{$amount})");
    }

    public function meetingScheduled(Meeting $m): void
    {
        $when = $m->scheduled_at?->format('d M H:i');
        $title = $m->notes ? ' "' . Str::limit($m->notes, 40, '…') . '"' : '';
        $this->log($m->student, 'meeting_scheduled', "Scheduled meeting{$title} for {$when}");
    }

    public function meetingRescheduled(Meeting $m, \DateTimeInterface $from): void
    {
        $fromStr = $from->format('d M H:i');
        $toStr = $m->scheduled_at?->format('d M H:i');
        $this->log($m->student, 'meeting_rescheduled', "Rescheduled meeting {$fromStr} → {$toStr}");
    }

    public function meetingCancelled(Meeting $m): void
    {
        $this->log($m->student, 'meeting_cancelled', 'Cancelled meeting');
    }

    public function roundEntered(RoundHistory $r): void
    {
        $stage = RoundNameToStage::stageName($r->round_name) ?? $r->round_name;
        $this->log($r->student, 'round_entered', "Round entered: {$stage} ({$r->round_name})");
    }

    public function roundOutcomeUpdated(RoundHistory $r): void
    {
        $this->log($r->student, 'round_outcome_updated', "Round {$r->round_name}: {$r->outcome}");
    }

    public function noteAdded(StudentNote $n): void
    {
        $excerpt = Str::limit($n->body, 60, '…');
        $this->log($n->student, 'note_added', "Added note: \"{$excerpt}\"");
    }

    public function leadCaptured(Student $s, string $source): void
    {
        $this->log($s, 'lead_captured', "Lead captured from {$source}");
    }

    private function log(Student $subject, string $event, string $description): void
    {
        activity()
            ->performedOn($subject)
            ->causedBy(auth()->user())
            ->event($event)
            ->log($description);
    }
}
