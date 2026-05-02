<?php

namespace Tests\Feature\Performance;

use App\Models\Meeting;
use App\Models\Payment;
use App\Models\Student;
use App\Models\User;
use App\Services\Performance\SignalCollector;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SignalCollectorTest extends TestCase
{
    use RefreshDatabase;

    private SignalCollector $collector;
    private CarbonImmutable $start;
    private CarbonImmutable $end;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->collector = new SignalCollector(
            terminalStages: ['Admission Confirmed', 'Closed'],
            staleThresholdDays: 60,
        );
        $this->start = CarbonImmutable::parse('2026-05-01')->startOfDay();
        $this->end   = CarbonImmutable::parse('2026-05-31')->endOfDay();
    }

    public function test_closed_won_and_deal_won_amount_count_only_admission_confirmed_in_period(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();

        // 2 wins for $owner inside period (advance paid 2026-05-xx)
        $win1 = Student::factory()->create([
            'owner_id' => $owner->id, 'referrer_id' => $owner->id,
            'stage' => 'Admission Confirmed', 'deal_amount' => 100000,
        ]);
        Payment::factory()->create([
            'student_id' => $win1->id, 'type' => 'advance',
            'amount' => 30000, 'received_at' => '2026-05-15',
        ]);
        $win2 = Student::factory()->create([
            'owner_id' => $owner->id, 'referrer_id' => $owner->id,
            'stage' => 'Admission Confirmed', 'deal_amount' => 150000,
        ]);
        Payment::factory()->create([
            'student_id' => $win2->id, 'type' => 'advance',
            'amount' => 40000, 'received_at' => '2026-05-20',
        ]);
        // 1 win for $owner OUTSIDE period (advance paid 2026-04-15)
        $winOld = Student::factory()->create([
            'owner_id' => $owner->id, 'referrer_id' => $owner->id,
            'stage' => 'Admission Confirmed', 'deal_amount' => 200000,
        ]);
        Payment::factory()->create([
            'student_id' => $winOld->id, 'type' => 'advance',
            'amount' => 60000, 'received_at' => '2026-04-15',
        ]);
        // 1 win for OTHER user inside period
        $winOther = Student::factory()->create([
            'owner_id' => $other->id, 'referrer_id' => $other->id,
            'stage' => 'Admission Confirmed', 'deal_amount' => 999999,
        ]);
        Payment::factory()->create([
            'student_id' => $winOther->id, 'type' => 'advance',
            'amount' => 50000, 'received_at' => '2026-05-15',
        ]);

        $signals = $this->collector->collect($owner, $this->start, $this->end);

        $this->assertSame(2, $signals->closedWon);
        $this->assertSame(250000, $signals->dealWonAmount);
    }

    public function test_rank_prob_avg_uses_only_open_students_with_non_null_cached_value(): void
    {
        $owner = User::factory()->create();

        // Two open students with cached probabilities — averaged
        $a = Student::factory()->create([
            'owner_id' => $owner->id, 'referrer_id' => $owner->id,
            'stage' => 'Lead Captured',
        ]);
        $b = Student::factory()->create([
            'owner_id' => $owner->id, 'referrer_id' => $owner->id,
            'stage' => 'Counselling Done',
        ]);
        // Won student — excluded (terminal)
        $w = Student::factory()->create([
            'owner_id' => $owner->id, 'referrer_id' => $owner->id,
            'stage' => 'Admission Confirmed',
        ]);
        // Open with NULL — excluded (skip nulls)
        Student::factory()->create([
            'owner_id' => $owner->id, 'referrer_id' => $owner->id,
            'stage' => 'Lead Captured',
        ]);

        // Set cache values directly, bypassing the observer (which would
        // recompute from the predictor and likely overwrite to null in
        // a test environment with no ranks-DB data)
        Student::query()->where('id', $a->id)->update(['rank_prob_first_choice' => 80]);
        Student::query()->where('id', $b->id)->update(['rank_prob_first_choice' => 60]);
        Student::query()->where('id', $w->id)->update(['rank_prob_first_choice' => 99]);

        $signals = $this->collector->collect($owner, $this->start, $this->end);

        $this->assertSame(70, $signals->rankProbAvg);   // (80 + 60) / 2
    }

    public function test_advance_received_sums_advance_payments_in_period_for_owner(): void
    {
        $owner = User::factory()->create();
        $student = Student::factory()->create([
            'owner_id' => $owner->id, 'referrer_id' => $owner->id,
            'stage' => 'Lead Captured',
        ]);

        Payment::factory()->create([
            'student_id' => $student->id, 'type' => 'advance',
            'amount' => 30000, 'received_at' => '2026-05-10',
        ]);
        Payment::factory()->create([
            'student_id' => $student->id, 'type' => 'advance',
            'amount' => 20000, 'received_at' => '2026-05-25',
        ]);
        // partial — different type, excluded
        Payment::factory()->create([
            'student_id' => $student->id, 'type' => 'partial',
            'amount' => 99999, 'received_at' => '2026-05-15',
        ]);
        // advance outside period
        Payment::factory()->create([
            'student_id' => $student->id, 'type' => 'advance',
            'amount' => 88888, 'received_at' => '2026-04-15',
        ]);

        $signals = $this->collector->collect($owner, $this->start, $this->end);

        $this->assertSame(50000, $signals->advanceReceived);
    }

    public function test_cases_captured_counts_owner_students_created_in_period(): void
    {
        $owner = User::factory()->create();
        // 3 inside period
        Student::factory()->count(3)->create([
            'owner_id' => $owner->id, 'referrer_id' => $owner->id,
            'created_at' => '2026-05-10', 'updated_at' => '2026-05-10',
        ]);
        // 1 outside period
        Student::factory()->create([
            'owner_id' => $owner->id, 'referrer_id' => $owner->id,
            'created_at' => '2026-04-10', 'updated_at' => '2026-04-10',
        ]);

        $signals = $this->collector->collect($owner, $this->start, $this->end);

        $this->assertSame(3, $signals->casesCaptured);
    }

    public function test_meetings_held_counts_held_meetings_in_period(): void
    {
        $owner = User::factory()->create();
        $s = Student::factory()->create([
            'owner_id' => $owner->id, 'referrer_id' => $owner->id,
        ]);

        Meeting::create([
            'student_id' => $s->id, 'owner_id' => $owner->id,
            'scheduled_at' => '2026-05-10 10:00:00',
            'status' => 'held', 'held_at' => '2026-05-10 11:00:00',
            'mode' => 'in_person', 'created_by_id' => $owner->id,
        ]);
        Meeting::create([
            'student_id' => $s->id, 'owner_id' => $owner->id,
            'scheduled_at' => '2026-05-15 10:00:00',
            'status' => 'held', 'held_at' => '2026-05-15 11:00:00',
            'mode' => 'phone', 'created_by_id' => $owner->id,
        ]);
        // Scheduled but not held
        Meeting::create([
            'student_id' => $s->id, 'owner_id' => $owner->id,
            'scheduled_at' => '2026-05-20 10:00:00',
            'status' => 'scheduled',
            'mode' => 'video', 'created_by_id' => $owner->id,
        ]);

        $signals = $this->collector->collect($owner, $this->start, $this->end);

        $this->assertSame(2, $signals->meetingsHeld);
    }

    public function test_open_leads_and_balance_amount_use_current_snapshot(): void
    {
        $owner = User::factory()->create();

        // Open student, deal 100k, paid 30k → balance 70k
        $s1 = Student::factory()->create([
            'owner_id' => $owner->id, 'referrer_id' => $owner->id,
            'stage' => 'Lead Captured', 'deal_amount' => 100000,
        ]);
        Payment::factory()->create([
            'student_id' => $s1->id, 'type' => 'advance',
            'amount' => 30000, 'received_at' => '2026-05-01',
        ]);
        // Open student, deal 50k, no payments → balance 50k
        Student::factory()->create([
            'owner_id' => $owner->id, 'referrer_id' => $owner->id,
            'stage' => 'Counselling Done', 'deal_amount' => 50000,
        ]);
        // Won (terminal) — excluded from open + balance
        Student::factory()->create([
            'owner_id' => $owner->id, 'referrer_id' => $owner->id,
            'stage' => 'Admission Confirmed', 'deal_amount' => 999999,
        ]);

        $signals = $this->collector->collect($owner, $this->start, $this->end);

        $this->assertSame(2, $signals->openLeads);
        $this->assertSame(120000, $signals->balanceAmount);
    }

    public function test_stale_open_counts_open_leads_older_than_threshold(): void
    {
        $owner = User::factory()->create();

        $stale = Student::factory()->create([
            'owner_id' => $owner->id, 'referrer_id' => $owner->id,
            'stage' => 'Lead Captured',
            'created_at' => '2026-01-01', 'updated_at' => '2026-01-01',
        ]);
        Student::factory()->create([
            'owner_id' => $owner->id, 'referrer_id' => $owner->id,
            'stage' => 'Lead Captured',
            'created_at' => '2026-04-15', 'updated_at' => '2026-04-15',
        ]);
        // Won — terminal, excluded
        Student::factory()->create([
            'owner_id' => $owner->id, 'referrer_id' => $owner->id,
            'stage' => 'Admission Confirmed',
            'created_at' => '2026-01-01', 'updated_at' => '2026-01-01',
        ]);

        $this->travelTo('2026-05-15');

        $signals = $this->collector->collect($owner, $this->start, $this->end);

        // 60d before 2026-05-15 = 2026-03-16. stale = updated_at before that.
        // s1 (Jan) → stale; s2 (Apr 15) → fresh
        $this->assertSame(1, $signals->staleOpen);
    }
}
