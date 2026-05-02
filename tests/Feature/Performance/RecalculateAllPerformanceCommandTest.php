<?php

namespace Tests\Feature\Performance;

use App\Models\Payment;
use App\Models\Student;
use App\Models\User;
use App\Models\UserPerformanceScore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class RecalculateAllPerformanceCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_command_writes_one_row_per_active_user_with_owned_students(): void
    {
        $a = User::factory()->create(['is_active' => true]);
        $b = User::factory()->create(['is_active' => true]);
        $idle = User::factory()->create(['is_active' => true]);  // active but owns nothing
        $inactive = User::factory()->create(['is_active' => false]);

        // a owns 2 students, b owns 1, inactive owns 1, idle owns nothing
        Student::factory()->count(2)->create(['owner_id' => $a->id, 'referrer_id' => $a->id]);
        Student::factory()->create(['owner_id' => $b->id, 'referrer_id' => $b->id]);
        Student::factory()->create(['owner_id' => $inactive->id, 'referrer_id' => $inactive->id]);

        $this->travelTo('2026-05-15');

        $exit = Artisan::call('performance:recalculate', ['--month' => '2026-05']);

        $this->assertSame(0, $exit);

        $rows = UserPerformanceScore::all();
        $userIds = $rows->pluck('user_id')->sort()->values()->all();

        $this->assertEquals(
            [$a->id, $b->id],
            $userIds,
            'Expected exactly the two active users with owned students; inactive + idle excluded.'
        );

        foreach ($rows as $r) {
            $this->assertSame('2026-05-01', $r->period_start->format('Y-m-d'));
            $this->assertSame('2026-05-31', $r->period_end->format('Y-m-d'));
            $this->assertGreaterThanOrEqual(0, $r->score);
            $this->assertLessThanOrEqual(100, $r->score);
            $this->assertNotNull($r->signal_breakdown);
            $this->assertNotNull($r->team_max_snapshot);
        }
    }

    public function test_command_is_idempotent_upserts_on_user_period(): void
    {
        $u = User::factory()->create(['is_active' => true]);
        Student::factory()->create(['owner_id' => $u->id, 'referrer_id' => $u->id]);

        $this->travelTo('2026-05-15');

        Artisan::call('performance:recalculate', ['--month' => '2026-05']);
        $this->assertSame(1, UserPerformanceScore::count());

        Artisan::call('performance:recalculate', ['--month' => '2026-05']);
        $this->assertSame(1, UserPerformanceScore::count());

        // Different month → new row
        Artisan::call('performance:recalculate', ['--month' => '2026-04']);
        $this->assertSame(2, UserPerformanceScore::count());
    }

    public function test_team_max_correctly_normalises_top_performer_to_100(): void
    {
        $top = User::factory()->create(['is_active' => true]);
        $mid = User::factory()->create(['is_active' => true]);

        // top: 3 wins × 200k each = 600k deal_won
        for ($i = 0; $i < 3; $i++) {
            $s = Student::factory()->create([
                'owner_id' => $top->id, 'referrer_id' => $top->id,
                'stage' => 'Admission Confirmed', 'deal_amount' => 200000,
            ]);
            Payment::factory()->create([
                'student_id' => $s->id, 'type' => 'advance',
                'amount' => 50000, 'received_at' => '2026-05-10',
            ]);
        }

        // mid: 1 win × 100k
        $s = Student::factory()->create([
            'owner_id' => $mid->id, 'referrer_id' => $mid->id,
            'stage' => 'Admission Confirmed', 'deal_amount' => 100000,
        ]);
        Payment::factory()->create([
            'student_id' => $s->id, 'type' => 'advance',
            'amount' => 25000, 'received_at' => '2026-05-12',
        ]);

        $this->travelTo('2026-05-15');
        Artisan::call('performance:recalculate', ['--month' => '2026-05']);

        $topRow = UserPerformanceScore::where('user_id', $top->id)->first();
        $midRow = UserPerformanceScore::where('user_id', $mid->id)->first();

        // Top should have 100 normalized closed_won; mid should have 100/3 ≈ 33.33
        $this->assertEquals(100, $topRow->signal_breakdown['sub_scores']['closed_won_norm']);
        $this->assertEqualsWithDelta(33.33, (float) $midRow->signal_breakdown['sub_scores']['closed_won_norm'], 0.5);

        // Top scores higher than mid
        $this->assertGreaterThan($midRow->score, $topRow->score);

        // team_max_snapshot reflects the max
        $this->assertSame(3, $topRow->team_max_snapshot['closed_won']);
        $this->assertSame(600000, $topRow->team_max_snapshot['deal_won_amount']);
    }

    public function test_command_with_no_users_to_score_succeeds_silently(): void
    {
        // Truncate the seeded users to ensure the scoring set is empty.
        // (Hard to do without affecting FKs; instead deactivate all + remove ownership)
        User::query()->update(['is_active' => false]);

        $exit = Artisan::call('performance:recalculate', ['--month' => '2026-05']);

        $this->assertSame(0, $exit);
        $this->assertSame(0, UserPerformanceScore::count());
    }
}
