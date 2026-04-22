<?php

namespace App\Filament\Pages;

use App\Models\Payment;
use App\Models\Student;
use App\Models\User;
use App\Services\PipelineSummary;
use App\Services\StageTransitionValidator;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Database\Eloquent\Builder;

class KanbanBoard extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-view-columns';

    protected static ?string $navigationLabel = 'Pipeline';

    protected static ?int $navigationSort = -1;

    protected static string $view = 'filament.pages.kanban-board';

    protected static ?string $title = 'Pipeline Report';

    protected static ?string $slug = 'kanban';

    /**
     * @return array<int, array{stage:string,count:int,deal:float,received:float,pending:float,students:\Illuminate\Support\Collection}>
     */
    public function getBoard(): array
    {
        $visibleIds = $this->visibleStudentQuery(auth()->user())->pluck('id');

        $students = Student::query()
            ->whereIn('id', $visibleIds)
            ->with(['owner:id,name', 'roundHistory' => fn ($q) => $q->latest('created_at')->limit(1)])
            ->orderByDesc('updated_at')
            ->get();

        $paymentsByStudent = Payment::query()
            ->whereIn('student_id', $visibleIds)
            ->selectRaw('student_id, SUM(amount) as total')
            ->groupBy('student_id')
            ->pluck('total', 'student_id');

        $byStage = $students->groupBy('stage');

        $columns = [];
        foreach (PipelineSummary::STAGES as $stage) {
            $group = $byStage->get($stage, collect());
            $deal = (float) $group->sum(fn ($s) => (float) ($s->deal_amount ?? 0));
            $received = (float) $group->sum(fn ($s) => (float) ($paymentsByStudent[$s->id] ?? 0));

            $columns[] = [
                'stage'    => $stage,
                'count'    => $group->count(),
                'deal'     => $deal,
                'received' => $received,
                'pending'  => max(0, $deal - $received),
                'students' => $group->map(fn ($s) => [
                    'id'           => $s->id,
                    'name'         => $s->name,
                    'phone'        => $s->phone,
                    'owner'        => $s->owner?->name,
                    'deal'         => (float) ($s->deal_amount ?? 0),
                    'received'     => (float) ($paymentsByStudent[$s->id] ?? 0),
                    'pending'      => max(0, (float) ($s->deal_amount ?? 0) - (float) ($paymentsByStudent[$s->id] ?? 0)),
                    'current_round' => $s->roundHistory->first()?->round_name,
                    'days_in_stage' => (int) $s->updated_at->diffInDays(now()),
                ]),
            ];
        }

        return $columns;
    }

    public function moveStudentToStage(int $studentId, string $newStage): array
    {
        $user = auth()->user();
        $student = $this->visibleStudentQuery($user)->whereKey($studentId)->first();

        if (! $student) {
            return $this->kanbanResponse(false, 'Not allowed.');
        }
        if (! in_array($newStage, PipelineSummary::STAGES, true)) {
            return $this->kanbanResponse(false, 'Unknown stage.');
        }
        if ($student->stage === $newStage) {
            return $this->kanbanResponse(true, 'No change.');
        }

        $original = $student->stage;
        $student->stage = $newStage;

        $errors = (new StageTransitionValidator)->forStageChange($student, $newStage);
        if ($errors !== []) {
            $student->stage = $original;
            Notification::make()
                ->title('Stage move blocked')
                ->body(implode(' ', $errors))
                ->danger()
                ->send();
            return $this->kanbanResponse(false, implode(' ', $errors));
        }

        $student->save();

        Notification::make()
            ->title("Moved to {$newStage}")
            ->success()
            ->send();

        return $this->kanbanResponse(true, 'ok');
    }

    /** @return array{ok:bool,message:string} */
    private function kanbanResponse(bool $ok, string $message): array
    {
        return ['ok' => $ok, 'message' => $message];
    }

    private function visibleStudentQuery(User $user): Builder
    {
        $q = Student::query();

        if ($user->hasRole('admin')) {
            return $q;
        }
        if ($user->hasRole('head')) {
            $teamIds = User::where('team_head_id', $user->id)->pluck('id')->all();
            $teamIds[] = $user->id;
            return $q->whereIn('owner_id', $teamIds);
        }
        return $q->where('owner_id', $user->id);
    }
}
