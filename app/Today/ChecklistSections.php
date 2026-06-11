<?php

namespace App\Today;

use App\Dashboard\Cards\ListCards\PaymentsToChaseCard;
use App\Models\Meeting;
use App\Models\Payment;
use App\Models\RoundHistory;
use App\Models\Student;
use App\Models\User;
use Illuminate\Support\Carbon;

class ChecklistSections
{
    private const TZ = 'Asia/Kolkata';

    /** @return array<int, array<string, mixed>> */
    public function forCard(string $cardId, User $viewer): array
    {
        return match ($cardId) {
            'today_meetings'      => $this->meetingsToday($viewer),
            'payments_to_chase'   => $this->paymentsToChase($viewer),
            'today_payments'      => $this->paymentsReceivedToday($viewer),
            'stuck_leads'         => $this->stuck($viewer),
            'seat_fee_pending'    => $this->seatFeePending($viewer),
            're_entry_candidates' => $this->reEntry($viewer),
            default               => [],
        };
    }

    /** @return array<int, array<string, mixed>> */
    private function meetingsToday(User $viewer): array
    {
        $start = Carbon::now(self::TZ)->startOfDay();
        $end   = Carbon::now(self::TZ)->endOfDay();

        return Meeting::query()
            ->whereBetween('scheduled_at', [$start, $end])
            ->whereIn('status', ['scheduled', 'held'])
            ->whereHas('student', fn ($q) => $q->visibleTo($viewer))
            ->with('student.owner')
            ->orderBy('scheduled_at')
            ->get()
            ->map(fn (Meeting $m) => [
                'student_id' => $m->student_id,
                'title'      => $m->student?->name ?? '—',
                'subtitle'   => trim(($m->student?->course ?? '—').' · '.($m->student?->owner?->name ?? 'Unassigned'), ' ·'),
                'time'       => $m->scheduled_at?->setTimezone(self::TZ)->format('H:i'),
                'amount'     => null,
                'dot'        => null,
                'pill'       => $m->status === 'held' ? 'held' : null,
            ])
            ->values()
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function paymentsToChase(User $viewer): array
    {
        return (new PaymentsToChaseCard())->query($viewer)
            ->limit(50)
            ->get()
            ->map(fn (Student $s) => [
                'student_id' => $s->id,
                'title'      => $s->name,
                'subtitle'   => $s->stage.' · '.($s->owner?->name ?? 'Unassigned'),
                'time'       => null,
                'amount'     => $s->pending_amount,
                'dot'        => $this->agingDot($s->updated_at),
                'pill'       => null,
            ])
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function paymentsReceivedToday(User $viewer): array
    {
        $start = Carbon::now(self::TZ)->startOfDay();
        $end   = Carbon::now(self::TZ)->endOfDay();

        return Payment::query()
            ->whereBetween('received_at', [$start, $end])
            ->whereHas('student', fn ($q) => $q->visibleTo($viewer))
            ->with('student')
            ->orderByDesc('received_at')
            ->get()
            ->map(fn (Payment $p) => [
                'student_id' => $p->student_id,
                'title'      => $p->student?->name ?? '—',
                'subtitle'   => ucfirst((string) $p->type).' · '.($p->mode ?? '—'),
                'time'       => $p->received_at?->setTimezone(self::TZ)->format('H:i'),
                'amount'     => (float) $p->amount,
                'dot'        => null,
                'pill'       => null,
            ])
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function stuck(User $viewer): array
    {
        return Student::query()
            ->stuck()
            ->visibleTo($viewer)
            ->with('owner')
            ->orderBy('updated_at')
            ->limit(50)
            ->get()
            ->map(fn (Student $s) => [
                'student_id' => $s->id,
                'title'      => $s->name,
                'subtitle'   => (string) $s->stage,
                'time'       => null,
                'amount'     => null,
                'dot'        => $this->agingDot($s->updated_at),
                'pill'       => $s->updated_at ? $s->updated_at->diffInDays(now()).' days' : null,
            ])
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function seatFeePending(User $viewer): array
    {
        return RoundHistory::query()
            ->seatFeePending()
            ->whereHas('student', fn ($q) => $q->visibleTo($viewer))
            ->with('student')
            ->orderBy('created_at')
            ->limit(50)
            ->get()
            ->map(fn (RoundHistory $r) => [
                'student_id' => $r->student_id,
                'title'      => $r->student?->name ?? '—',
                'subtitle'   => trim(($r->round_name ?? '—').' · '.($r->allotted_college ?? 'fee due'), ' ·'),
                'time'       => null,
                'amount'     => $r->seat_fee_amount !== null ? (float) $r->seat_fee_amount : null,
                'dot'        => $this->agingDot($r->created_at),
                'pill'       => null,
            ])
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function reEntry(User $viewer): array
    {
        return RoundHistory::query()
            ->reEntryCandidates()
            ->whereHas('student', fn ($q) => $q->visibleTo($viewer))
            ->with('student.owner')
            ->orderByDesc('created_at')
            ->limit(50)
            ->get()
            ->map(fn (RoundHistory $r) => [
                'student_id' => $r->student_id,
                'title'      => $r->student?->name ?? '—',
                'subtitle'   => ($r->round_name ?? '—').' · re-eligible',
                'time'       => null,
                'amount'     => null,
                'dot'        => $this->agingDot($r->student?->updated_at),
                'pill'       => null,
            ])
            ->all();
    }

    private function agingDot(?Carbon $ts): ?string
    {
        if ($ts === null) {
            return null;
        }
        $days = $ts->diffInDays(now());

        return $days <= 3 ? '#10B981' : ($days <= 14 ? '#F59E0B' : '#EF4444');
    }
}
