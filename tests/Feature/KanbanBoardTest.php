<?php

namespace Tests\Feature;

use App\Filament\Pages\KanbanBoard;
use App\Models\Payment;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KanbanBoardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function student(array $overrides): Student
    {
        $nikhil = User::where('email', 'nikhil@davya.local')->first();

        return Student::create(array_merge([
            'phone'       => '9'.rand(100000000, 999999999),
            'name'        => 'S',
            'owner_id'    => $nikhil->id,
            'referrer_id' => $nikhil->id,
            'lead_source' => 'Nikhil',
        ], $overrides));
    }

    public function test_board_has_one_entry_per_plan_stage_and_counts_students_per_column(): void
    {
        $s1 = $this->student(['stage' => 'Lead Captured']);
        $s2 = $this->student(['stage' => 'Lead Captured']);
        $this->student(['stage' => 'Onboarded', 'deal_amount' => 50000]);

        $sumit = User::where('email', 'sumit@davya.local')->first();
        $this->actingAs($sumit);

        $page = new KanbanBoard;
        $board = $page->getBoard();

        $this->assertCount(10, $board);
        $byStage = collect($board)->keyBy('stage');

        $this->assertSame(2, $byStage['Lead Captured']['count']);
        $this->assertSame(1, $byStage['Onboarded']['count']);
        $this->assertSame(0, $byStage['Closed']['count']);
    }

    public function test_column_aggregates_deal_received_and_pending(): void
    {
        $sumit = User::where('email', 'sumit@davya.local')->first();
        $nikhil = User::where('email', 'nikhil@davya.local')->first();

        $a = $this->student(['stage' => 'Onboarded', 'deal_amount' => 100000]);
        Payment::create([
            'student_id' => $a->id, 'type' => 'advance', 'amount' => 30000,
            'received_at' => now(), 'recorded_by_user_id' => $nikhil->id,
        ]);
        $b = $this->student(['stage' => 'Onboarded', 'deal_amount' => 50000]);
        Payment::create([
            'student_id' => $b->id, 'type' => 'advance', 'amount' => 20000,
            'received_at' => now(), 'recorded_by_user_id' => $nikhil->id,
        ]);

        $this->actingAs($sumit);
        $board = collect((new KanbanBoard)->getBoard())->keyBy('stage');

        $this->assertEqualsWithDelta(150000.0, $board['Onboarded']['deal'], 0.01);
        $this->assertEqualsWithDelta(50000.0, $board['Onboarded']['received'], 0.01);
        $this->assertEqualsWithDelta(100000.0, $board['Onboarded']['pending'], 0.01);
        $this->assertSame(2, $board['Onboarded']['count']);
    }

    public function test_member_only_sees_own_students_in_kanban(): void
    {
        $nikhil = User::where('email', 'nikhil@davya.local')->first();
        $nisha  = User::where('email', 'nisha@davya.local')->first();
        $poonam = User::where('email', 'poonam@davya.local')->first();

        $nishaStudent  = $this->student(['owner_id' => $nisha->id,  'referrer_id' => $nisha->id,  'lead_source' => 'Nisha',  'stage' => 'Lead Captured']);
        $poonamStudent = $this->student(['owner_id' => $poonam->id, 'referrer_id' => $poonam->id, 'lead_source' => 'Poonam', 'stage' => 'Lead Captured']);

        $this->actingAs($nisha);
        $board = collect((new KanbanBoard)->getBoard())->keyBy('stage');

        $this->assertSame(1, $board['Lead Captured']['count']);
        $ids = collect($board['Lead Captured']['students'])->pluck('id')->all();
        $this->assertContains($nishaStudent->id, $ids);
        $this->assertNotContains($poonamStudent->id, $ids);
    }

    // --- drag-drop (server side of it) ---

    public function test_moveStudentToStage_updates_stage_when_allowed(): void
    {
        $sumit = User::where('email', 'sumit@davya.local')->first();
        $s = $this->student(['stage' => 'Lead Captured']);

        $this->actingAs($sumit);
        $res = (new KanbanBoard)->moveStudentToStage($s->id, 'Meeting Scheduled');

        $this->assertTrue($res['ok']);
        $this->assertSame('Meeting Scheduled', $s->fresh()->stage);
    }

    public function test_moveStudentToStage_blocks_closed_without_close_reason(): void
    {
        $sumit = User::where('email', 'sumit@davya.local')->first();
        $s = $this->student(['stage' => 'Onboarded']);

        $this->actingAs($sumit);
        $res = (new KanbanBoard)->moveStudentToStage($s->id, 'Closed');

        $this->assertFalse($res['ok']);
        $this->assertStringContainsString('close_reason', $res['message']);
        $this->assertSame('Onboarded', $s->fresh()->stage);
    }

    public function test_moveStudentToStage_rejects_students_outside_visibility(): void
    {
        $nisha  = User::where('email', 'nisha@davya.local')->first();
        $poonam = User::where('email', 'poonam@davya.local')->first();

        $poonamStudent = $this->student([
            'owner_id' => $poonam->id, 'referrer_id' => $poonam->id, 'lead_source' => 'Poonam',
            'stage' => 'Lead Captured',
        ]);

        $this->actingAs($nisha);
        $res = (new KanbanBoard)->moveStudentToStage($poonamStudent->id, 'Meeting Scheduled');

        $this->assertFalse($res['ok']);
        $this->assertSame('Lead Captured', $poonamStudent->fresh()->stage);
    }

    public function test_head_sees_team_and_self_students(): void
    {
        $nikhil = User::where('email', 'nikhil@davya.local')->first();
        $nisha  = User::where('email', 'nisha@davya.local')->first();
        $poonam = User::where('email', 'poonam@davya.local')->first();

        $nikhilStudent = $this->student(['owner_id' => $nikhil->id, 'referrer_id' => $nikhil->id, 'lead_source' => 'Nikhil', 'stage' => 'Onboarded']);
        $nishaStudent  = $this->student(['owner_id' => $nisha->id,  'referrer_id' => $nisha->id,  'lead_source' => 'Nisha',  'stage' => 'Onboarded']);
        $poonamStudent = $this->student(['owner_id' => $poonam->id, 'referrer_id' => $poonam->id, 'lead_source' => 'Poonam', 'stage' => 'Onboarded']);

        $this->actingAs($nikhil);
        $board = collect((new KanbanBoard)->getBoard())->keyBy('stage');

        $ids = collect($board['Onboarded']['students'])->pluck('id')->all();
        $this->assertContains($nikhilStudent->id, $ids);
        $this->assertContains($nishaStudent->id, $ids);
        $this->assertNotContains($poonamStudent->id, $ids);
    }
}
