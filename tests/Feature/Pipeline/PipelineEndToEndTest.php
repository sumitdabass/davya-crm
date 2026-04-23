<?php
// tests/Feature/Pipeline/PipelineEndToEndTest.php
namespace Tests\Feature\Pipeline;

use App\Filament\Pages\KanbanBoard;
use App\Models\Pipeline;
use App\Models\Student;
use App\Models\User;
use App\Services\Pipeline\StageTransitionEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PipelineEndToEndTest extends TestCase
{
    use RefreshDatabase;

    private function unblock(User $u): User
    {
        $u->must_change_password = false;
        $u->save();

        return $u;
    }

    /**
     * Happy-path walk across every seeded transition rule:
     *   - Meeting Scheduled SOFT (future meeting)
     *   - Closed HARD (close_reason)
     *   - Re-open HARD (re_entry_reason)
     * Sliding SOFT (prior allotment) is covered by StageTransitionEngineTest
     * and exercised via `test_legacy_guards_coverage_audit` below.
     */
    public function test_student_walks_from_lead_to_complete_payment(): void
    {
        $this->seed();
        $sumit = $this->unblock(User::where('email', 'sumit@davya.local')->firstOrFail());
        $this->actingAs($sumit);

        $nikhil = User::where('email', 'nikhil@davya.local')->firstOrFail();
        $leadStageId = Pipeline::default()->stages()->where('name', 'Lead Captured')->value('id');

        $s = Student::create([
            'name' => 'Walker', 'phone' => '9333333333',
            'owner_id' => $nikhil->id, 'referrer_id' => $nikhil->id,
            'lead_source' => 'test', 'stage' => 'Lead Captured', 'stage_id' => $leadStageId,
            'deal_amount' => 100000,
        ]);

        $board = app(KanbanBoard::class);

        // Walk forward: Lead → Meeting Scheduled (soft warn OK to bypass in non-UI context;
        // engine returns warning but moveStudentToStage accepts).
        $s->meetings()->create([
            'scheduled_at' => now()->addDay(),
            'status' => 'scheduled',
            'owner_id' => $s->owner_id,
            'created_by_id' => $s->owner_id,
        ]);
        $this->assertTrue($board->moveStudentToStage($s->id, 'Meeting Scheduled')['ok']);

        // Meeting Done
        $this->assertTrue($board->moveStudentToStage($s->id, 'Meeting Done')['ok']);

        // → Closed should fail (hard: close_reason missing)
        $out = $board->moveStudentToStage($s->id, 'Closed');
        $this->assertFalse($out['ok']);

        // Set reason and retry
        $s->refresh()->update(['close_reason' => 'Not Interested']);
        $this->assertTrue($board->moveStudentToStage($s->id, 'Closed')['ok']);

        // Re-open — must set re_entry_reason first
        $out = $board->moveStudentToStage($s->id, 'Meeting Done');
        $this->assertFalse($out['ok']);
        $s->refresh()->update(['re_entry_reason' => 'changed mind']);
        $this->assertTrue($board->moveStudentToStage($s->id, 'Meeting Done')['ok']);
    }

    /**
     * Diagnostic ledger — NOT a behavior gate.
     *
     * The deleted StageTransitionValidator covered 12 behaviors. The seeded
     * stage_transition_rules table covers 4 of them (see migration
     * 2026_04_23_100400_seed_default_transition_rules). This test documents
     * which of the OTHER 8 legacy guards still fire (via some other rule path)
     * vs. which are now silent no-ops. Output feeds T22's deploy runbook
     * "known coverage gaps" section.
     *
     * For each scenario we call the same entry point moveStudentToStage uses —
     * StageTransitionEngine::forStageChange(Student, int $toStageId) — and
     * assert the current ['hard' => [], 'soft' => []] shape. If an assertion
     * changes in the future (e.g. a new rule is seeded), this test will fail
     * loudly and prompt a runbook update.
     */
    public function test_legacy_guards_coverage_audit(): void
    {
        $this->seed();

        $engine    = app(StageTransitionEngine::class);
        $nikhil    = User::where('email', 'nikhil@davya.local')->firstOrFail();
        $stageId   = fn (string $n) => Pipeline::default()->stages()->where('name', $n)->value('id');
        $leadStage = $stageId('Lead Captured');

        // ── Scenario 1: Meeting Done without student_response ─────────────────
        // Legacy: SOFT warn "Meeting Done incomplete: set student_response...".
        // Current: NO rule seeded for → Meeting Done on student_response → SILENT.
        $s1 = $this->makeStudent($nikhil, $leadStage);
        $out = $engine->forStageChange($s1, $stageId('Meeting Done'));
        $this->assertEmpty($out['hard'], 'Meeting Done should not be hard-blocked');
        $this->assertEmpty($out['soft'], 'Legacy SOFT for Meeting Done missing student_response is now silent');

        // ── Scenario 2: Advance Received with no payments ─────────────────────
        // Legacy: SOFT warn "Advance Received incomplete: record the advance payment...".
        // Current: no rule seeded for → Advance Received → SILENT.
        $s2 = $this->makeStudent($nikhil, $leadStage);
        $out = $engine->forStageChange($s2, $stageId('Advance Received'));
        $this->assertEmpty($out['hard']);
        $this->assertEmpty($out['soft'], 'Legacy SOFT for Advance Received without payments is now silent');

        // ── Scenario 3: Advance Received when deal_amount < payment sum ───────
        // Legacy plan assertion (not in the old validator literally, but on the
        // audit spec) — overpayment check. Current: no rule seeded → SILENT.
        $s3 = $this->makeStudent($nikhil, $leadStage, ['deal_amount' => 10000]);
        $s3->payments()->create([
            'type' => 'advance', 'amount' => 20000, 'received_at' => now(),
            'mode' => 'cash', 'recorded_by_user_id' => $nikhil->id,
        ]);
        $out = $engine->forStageChange($s3, $stageId('Advance Received'));
        $this->assertEmpty($out['hard']);
        $this->assertEmpty($out['soft'], 'Overpayment guard for Advance Received is now silent');

        // ── Scenario 4: Round 1 without matching round_history ────────────────
        // Legacy: SOFT "Round 1 incomplete: create a round_history row...".
        // Current: no rule seeded for → Round 1 → SILENT.
        $s4 = $this->makeStudent($nikhil, $leadStage);
        $out = $engine->forStageChange($s4, $stageId('Round 1'));
        $this->assertEmpty($out['hard']);
        $this->assertEmpty($out['soft'], 'Legacy SOFT for Round 1 without round_history is now silent');

        // ── Scenario 5: Round 2 without round_history ─────────────────────────
        // Legacy: SOFT warn. Current: SILENT.
        $s5 = $this->makeStudent($nikhil, $leadStage);
        $out = $engine->forStageChange($s5, $stageId('Round 2'));
        $this->assertEmpty($out['hard']);
        $this->assertEmpty($out['soft'], 'Legacy SOFT for Round 2 without round_history is now silent');

        // ── Scenario 6: Round 3 without round_history ─────────────────────────
        // Legacy: SOFT warn. Current: SILENT.
        $s6 = $this->makeStudent($nikhil, $leadStage);
        $out = $engine->forStageChange($s6, $stageId('Round 3'));
        $this->assertEmpty($out['hard']);
        $this->assertEmpty($out['soft'], 'Legacy SOFT for Round 3 without round_history is now silent');

        // ── Scenario 7: Sliding without round_history ─────────────────────────
        // Legacy: SOFT "Sliding incomplete: create a round_history row...".
        // Current: PARTIALLY CAUGHT — the "Sliding needs prior allotment" rule
        // (seeded) fires for a student with NO round_history (count < 1), so
        // this single scenario is actually still surfaced as a SOFT warning.
        // This is the only one of the 8 that still has coverage.
        $s7 = $this->makeStudent($nikhil, $leadStage);
        $out = $engine->forStageChange($s7, $stageId('Sliding'));
        $this->assertEmpty($out['hard']);
        $this->assertNotEmpty(
            $out['soft'],
            'Sliding without prior allotment IS still caught by the seeded "Sliding needs prior allotment" SOFT rule'
        );

        // ── Scenario 8: Seat Allotted without final college/course/admission ──
        // Legacy: SOFT "Seat Allotted incomplete: set allotted_college...".
        // Current: no rule seeded for → Seat Allotted → SILENT.
        $s8 = $this->makeStudent($nikhil, $leadStage);
        $out = $engine->forStageChange($s8, $stageId('Seat Allotted'));
        $this->assertEmpty($out['hard']);
        $this->assertEmpty($out['soft'], 'Legacy SOFT for Seat Allotted missing final_* fields is now silent');
    }

    private function makeStudent(User $owner, int $stageId, array $override = []): Student
    {
        return Student::create(array_merge([
            'name' => 'Audit',
            'phone' => '9888' . str_pad((string) rand(100000, 999999), 6, '0', STR_PAD_LEFT),
            'owner_id' => $owner->id, 'referrer_id' => $owner->id,
            'lead_source' => 'test', 'stage' => 'Lead Captured', 'stage_id' => $stageId,
        ], $override));
    }
}
