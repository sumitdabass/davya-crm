<?php

namespace App\Services\Performance;

use App\Models\Meeting;
use App\Models\Payment;
use App\Models\Student;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class SignalCollector
{
    /**
     * @param array<int,string> $terminalStages
     */
    public function __construct(
        private readonly array $terminalStages,
        private readonly int $staleThresholdDays,
    ) {}

    public function collect(User $user, CarbonImmutable $periodStart, CarbonImmutable $periodEnd): SignalSet
    {
        $startTs = $periodStart->startOfDay();
        $endTs   = $periodEnd->endOfDay();
        $staleBefore = CarbonImmutable::now()->subDays($this->staleThresholdDays);

        // 1. closed_won — students currently 'Admission Confirmed' whose FIRST advance
        //    payment landed in the period. The dropped `admission_date` column has
        //    been replaced by payment-derived bucketing: a deal closes when the
        //    advance is received, so MIN(advance.received_at) is the canonical
        //    win timestamp.
        $wonStudentIds = DB::table('payments')
            ->where('type', 'advance')
            ->whereIn('student_id', function ($q) use ($user) {
                $q->select('id')->from('students')
                    ->where('owner_id', $user->id)
                    ->where('stage', 'Admission Confirmed');
            })
            ->groupBy('student_id')
            ->havingRaw('MIN(received_at) BETWEEN ? AND ?', [$startTs, $endTs])
            ->pluck('student_id');

        $closedWon = $wonStudentIds->count();

        // 2. deal_won_amount
        $dealWonAmount = $closedWon > 0
            ? (int) round((float) Student::whereIn('id', $wonStudentIds)->sum('deal_amount'))
            : 0;

        // 3. rank_prob_avg — over current OPEN students with a non-null cached probability
        $rankProbAvg = (int) round((float) Student::query()
            ->where('owner_id', $user->id)
            ->whereNotIn('stage', $this->terminalStages)
            ->whereNotNull('rank_prob_first_choice')
            ->avg('rank_prob_first_choice'));

        // 4. advance_received — payments.type='advance' joined to owner's students, received_at in period
        $advanceReceived = (int) round((float) Payment::query()
            ->join('students', 'students.id', '=', 'payments.student_id')
            ->where('students.owner_id', $user->id)
            ->where('payments.type', 'advance')
            ->whereBetween('payments.received_at', [$startTs, $endTs])
            ->sum('payments.amount'));

        // 5. cases_captured — students created this period under this owner
        $casesCaptured = (int) Student::query()
            ->where('owner_id', $user->id)
            ->whereBetween('created_at', [$startTs, $endTs])
            ->count();

        // 6. meetings_held — meetings with status='held' under this owner, held_at in period
        $meetingsHeld = (int) Meeting::query()
            ->where('owner_id', $user->id)
            ->where('status', 'held')
            ->whereBetween('held_at', [$startTs, $endTs])
            ->count();

        // 7. open_leads — current pipeline (snapshot)
        $openQuery = Student::query()
            ->where('owner_id', $user->id)
            ->whereNotIn('stage', $this->terminalStages);
        $openLeads = (int) $openQuery->count();

        // 8. balance_amount — outstanding receivables across owner's open students
        $balanceAmount = (int) round((float) DB::table('students')
            ->leftJoin('payments', 'payments.student_id', '=', 'students.id')
            ->where('students.owner_id', $user->id)
            ->whereNotIn('students.stage', $this->terminalStages)
            ->groupBy('students.id', 'students.deal_amount')
            ->select(
                'students.id',
                DB::raw('students.deal_amount - COALESCE(SUM(payments.amount), 0) AS balance')
            )
            ->get()
            ->sum('balance'));

        // 9. stale_open — open leads not touched in N days
        $staleOpen = (int) Student::query()
            ->where('owner_id', $user->id)
            ->whereNotIn('stage', $this->terminalStages)
            ->where('updated_at', '<', $staleBefore)
            ->count();

        return new SignalSet(
            closedWon: $closedWon,
            dealWonAmount: $dealWonAmount,
            rankProbAvg: $rankProbAvg,
            advanceReceived: $advanceReceived,
            casesCaptured: $casesCaptured,
            meetingsHeld: $meetingsHeld,
            openLeads: $openLeads,
            balanceAmount: $balanceAmount,
            staleOpen: $staleOpen,
        );
    }
}
