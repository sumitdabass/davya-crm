<?php

namespace Tests\Feature\Pipeline;

use App\Filament\Pages\KanbanBoard;
use App\Models\Pipeline;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class KanbanDynamicStagesTest extends TestCase
{
    use RefreshDatabase;

    private function unblock(User $u): User
    {
        $u->must_change_password = false;
        $u->save();

        return $u;
    }

    public function test_kanban_board_returns_13_columns_from_db(): void
    {
        $this->seed();
        $sumit = $this->unblock(User::where('email', 'sumit@davya.local')->firstOrFail());
        $this->actingAs($sumit);
        $board = Livewire::test(KanbanBoard::class)->instance()->getBoard();
        $this->assertCount(13, $board);
        $this->assertSame('Lead Captured', $board[0]['stage']);
        $this->assertSame('Closed', end($board)['stage']);
    }

    public function test_move_student_to_closed_without_reason_is_blocked(): void
    {
        $this->seed();
        $sumit = $this->unblock(User::where('email', 'sumit@davya.local')->firstOrFail());
        $this->actingAs($sumit);

        $nikhil = User::where('email', 'nikhil@davya.local')->firstOrFail();
        $stageId = Pipeline::default()->stages()->where('name', 'Meeting Done')->value('id');
        $s = Student::create([
            'name' => 'X', 'phone' => '9888888881', 'owner_id' => $nikhil->id, 'referrer_id' => $nikhil->id,
            'lead_source' => 'test', 'stage' => 'Meeting Done', 'stage_id' => $stageId,
        ]);

        $board = app(KanbanBoard::class);
        $result = $board->moveStudentToStage($s->id, 'Closed');
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('close_reason', implode(' ', $result['errors']));
    }
}
