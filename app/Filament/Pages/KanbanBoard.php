<?php

namespace App\Filament\Pages;

use App\Models\Payment;
use App\Models\Stage;
use App\Models\Student;
use App\Models\User;
use App\Services\PipelineSummary;
use App\Services\Pipeline\PipelineConfig;
use App\Services\Pipeline\StageTransitionEngine;
use App\StudentFields\KanbanExtrasFormatter;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Url;

class KanbanBoard extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-view-columns';

    protected static ?string $navigationLabel = 'Pipeline';

    protected static ?int $navigationSort = -1;

    protected static string $view = 'filament.pages.kanban-board';

    protected static ?string $title = 'Pipeline Report';

    protected static ?string $slug = 'kanban';

    #[Url(as: 'owner')]
    public ?string $filterOwner = null;

    #[Url(as: 'course')]
    public ?string $filterCourse = null;

    #[Url(as: 'round')]
    public ?string $filterRound = null;

    #[Url(as: 'source')]
    public ?string $filterLeadSource = null;

    #[Url(as: 'pending')]
    public bool $filterHasPending = false;

    public function resetFilters(): void
    {
        $this->filterOwner = null;
        $this->filterCourse = null;
        $this->filterRound = null;
        $this->filterLeadSource = null;
        $this->filterHasPending = false;
    }

    /**
     * Filter dropdown options, scoped to what the viewer is allowed to see.
     *
     * @return array{owners: array<int,string>, courses: array<string,string>, rounds: array<string,string>, sources: array<string,string>}
     */
    public function getFilterOptions(): array
    {
        $base = $this->visibleStudentQuery(auth()->user());

        $ownerIds = (clone $base)->distinct()->pluck('owner_id')->filter()->all();
        $owners = User::whereIn('id', $ownerIds)->orderBy('name')->pluck('name', 'id')->all();

        $courses = (clone $base)->whereNotNull('course')->where('course', '!=', '')
            ->distinct()->orderBy('course')->pluck('course')->all();

        $rounds = (clone $base)->whereNotNull('current_round')->where('current_round', '!=', '')
            ->distinct()->orderBy('current_round')->pluck('current_round')->all();

        $sources = (clone $base)->whereNotNull('lead_source')->where('lead_source', '!=', '')
            ->distinct()->orderBy('lead_source')->pluck('lead_source')->all();

        return [
            'owners'  => $owners,
            'courses' => array_combine($courses, $courses),
            'rounds'  => array_combine($rounds, $rounds),
            'sources' => array_combine($sources, $sources),
        ];
    }

    /**
     * @return array<int, array{stage:string,count:int,deal:float,received:float,pending:float,students:\Illuminate\Support\Collection}>
     */
    public function getBoard(): array
    {
        $visibleIds = $this->filteredStudentQuery(auth()->user())->pluck('id');

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

        $extrasFormatter = new KanbanExtrasFormatter();

        $columns = [];
        foreach (PipelineSummary::stages() as $stageName) {
            $stageModel = app(PipelineConfig::class)->stageByName($stageName);
            $group = $byStage->get($stageName, collect());
            $deal = (float) $group->sum(fn ($s) => (float) ($s->deal_amount ?? 0));
            $received = (float) $group->sum(fn ($s) => (float) ($paymentsByStudent[$s->id] ?? 0));

            // visual-v2: received_total / pending_total exposed for kanban column headers
            $columns[] = [
                'stage'          => $stageName,
                'stage_type'     => $stageModel ? $this->stageType($stageModel) : 'active',
                'count'          => $group->count(),
                'deal'           => $deal,
                'received'       => $received,
                'pending'        => max(0, $deal - $received),
                'received_total' => $received,
                'pending_total'  => max(0, $deal - $received),
                'students' => $group->map(fn ($s) => [
                    'id'              => $s->id,
                    'name'            => $s->name,
                    'phone'           => $s->phone,
                    'owner'           => $s->owner?->name,
                    'owner_id'        => $s->owner_id,
                    'owner_name'      => $s->owner?->name ?? '??',
                    'course'          => $s->course,
                    'student_response' => $s->student_response,
                    'deal'            => (float) ($s->deal_amount ?? 0),
                    'received'        => (float) ($paymentsByStudent[$s->id] ?? 0),
                    'pending'         => max(0, (float) ($s->deal_amount ?? 0) - (float) ($paymentsByStudent[$s->id] ?? 0)),
                    'current_round'   => $s->roundHistory->first()?->round_name,
                    'days_in_stage'   => (int) $s->updated_at->diffInDays(now()),
                    'extras'          => $extrasFormatter->format($s),
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

        $config = app(PipelineConfig::class);
        $target = $config->stageByName($newStage);
        if (! $target) {
            return $this->kanbanResponse(false, "Unknown stage: $newStage");
        }
        if ($student->stage === $newStage) {
            return $this->kanbanResponse(true, 'No change.');
        }

        // Evaluate the engine BEFORE mutating stage_id — the engine reads
        // $student->stage_id to determine the "from" stage for rule matching.
        $out = app(StageTransitionEngine::class)->forStageChange($student, $target->id);

        if (! empty($out['hard'])) {
            // Only notify without missing fields — frontend shows inline fix modal when missing_fields present.
            if (empty($out['hard_missing_fields'])) {
                Notification::make()
                    ->title('Stage move blocked')
                    ->body(implode("\n", $out['hard']))
                    ->danger()
                    ->persistent()
                    ->send();
            }

            return [
                'ok' => false,
                'message' => implode(' ', $out['hard']),
                'errors' => $out['hard'],
                'missing_fields' => $out['hard_missing_fields'],
                'student_id' => $student->id,
                'student_name' => $student->name,
                'target_stage' => $newStage,
            ];
        }

        $student->stage = $newStage;
        $student->stage_id = $target->id;
        $student->save();

        if (! empty($out['soft'])) {
            Notification::make()
                ->title("Moved to {$newStage} — some fields still missing")
                ->body(implode("\n", $out['soft']))
                ->warning()
                ->persistent()
                ->send();
        } else {
            Notification::make()
                ->title("Moved to {$newStage}")
                ->success()
                ->send();
        }

        return $this->kanbanResponse(true, 'ok');
    }

    private function stageType(Stage $stage): string
    {
        $name = mb_strtolower($stage->name);
        if ($stage->stage_type === 'CLOSED_WON')  { return 'won'; }
        if ($stage->stage_type === 'CLOSED_LOST') { return 'lost'; }
        if (str_contains($name, 'new'))     { return 'new'; }
        if (str_contains($name, 'meeting')) { return 'meeting'; }
        if (str_contains($name, 'visit'))   { return 'meeting'; }
        if (str_contains($name, 'advance')) { return 'advance'; }
        if (str_contains($name, 'round'))   { return 'round'; }
        if (str_contains($name, 'offline')) { return 'offline'; }
        return 'active';
    }

    /**
     * @param  string[]  $errors
     * @return array{ok:bool,message:string,errors:string[]}
     */
    private function kanbanResponse(bool $ok, string $message, array $errors = []): array
    {
        return ['ok' => $ok, 'message' => $message, 'errors' => $errors];
    }

    /**
     * Fill in the missing student fields then retry moveStudentToStage.
     * Called by the kanban fix-up modal. Only whitelisted fields can be set.
     *
     * @param  array<string,mixed>  $fieldUpdates
     */
    public function fixAndMove(int $studentId, string $newStage, array $fieldUpdates): array
    {
        $user = auth()->user();
        $student = $this->visibleStudentQuery($user)->whereKey($studentId)->first();
        if (! $student) {
            return $this->kanbanResponse(false, 'Not allowed.');
        }

        $allowed = [
            'close_reason', 're_entry_reason', 'student_response', 'deal_amount', 'course',
            'category', 'plan', 'meeting_date', 'meeting_location', 'current_round',
            'final_college', 'final_course', 'admission_date', 'is_ipu_registered',
            'ipu_login_code', 'father_name', 'twelfth_marks', 'exam_appeared', 'refund_amount',
        ];
        $dirty = array_intersect_key($fieldUpdates, array_flip($allowed));
        if (! empty($dirty)) {
            foreach ($dirty as $k => $v) {
                $student->{$k} = $v === '' ? null : $v;
            }
            $student->save();
        }

        return $this->moveStudentToStage($studentId, $newStage);
    }

    private function visibleStudentQuery(User $user): Builder
    {
        // Single source of truth — stays in lockstep with list/edit scope + policy.
        return Student::query()->visibleTo($user);
    }

    private function filteredStudentQuery(User $user): Builder
    {
        $q = $this->visibleStudentQuery($user);

        if (filled($this->filterOwner)) {
            $q->where('owner_id', (int) $this->filterOwner);
        }
        if (filled($this->filterCourse)) {
            $q->where('course', $this->filterCourse);
        }
        if (filled($this->filterRound)) {
            $q->where('current_round', $this->filterRound);
        }
        if (filled($this->filterLeadSource)) {
            $q->where('lead_source', $this->filterLeadSource);
        }
        if ($this->filterHasPending) {
            $q->whereRaw('COALESCE(deal_amount, 0) > COALESCE((SELECT SUM(amount) FROM payments WHERE payments.student_id = students.id), 0)');
        }

        return $q;
    }
}
