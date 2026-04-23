<?php

namespace App\Dashboard\Cards\Stat;

use App\Dashboard\Card;
use App\Dashboard\DrillDownPayload;
use App\Models\Stage;
use App\Models\Student;
use App\Models\User;

class StageStatCard implements Card
{
    public function __construct(private readonly Stage $stage) {}

    public function id(): string { return 'stage.'.$this->stage->id; }
    public function label(): string { return $this->stage->name; }
    public function surface(): string { return 'dashboard'; }
    public function isDefaultOn(string $surface): bool { return $surface === 'dashboard'; }
    public function type(): string { return 'stat'; }

    public function render(User $viewer): string
    {
        $q = $this->baseQuery($viewer);
        $count = (clone $q)->count();
        $total = (float) (clone $q)
            ->leftJoin('payments', 'payments.student_id', '=', 'students.id')
            ->where('payments.amount', '>', 0)
            ->sum('payments.amount');

        return view('components.dashboard.stat-body', [
            'cardId' => $this->id(),
            'label' => $this->label(),
            'value' => (string) $count,
            'secondary' => '₹ '.number_format($total, 0, '.', ','),
            'drillable' => true,
        ])->render();
    }

    public function drillDown(User $viewer): ?DrillDownPayload
    {
        return new DrillDownPayload(
            title: $this->stage->name,
            query: $this->baseQuery($viewer),
            columns: [
                ['key' => 'name', 'label' => 'Name'],
                ['key' => 'phone', 'label' => 'Phone'],
                ['key' => 'owner_name', 'label' => 'Owner'],
                ['key' => 'course', 'label' => 'Course'],
                ['key' => 'days_in_stage', 'label' => 'Days in stage'],
            ],
            csvFilenamePrefix: 'stage-'.str($this->stage->name)->slug()->toString(),
        );
    }

    public function viewAllHref(User $viewer): ?string
    {
        return route('filament.admin.resources.students.index')
            .'?tableFilters[stage_id][value]='.$this->stage->id;
    }

    public function isAvailableFor(User $viewer): bool { return true; }

    private function baseQuery(User $viewer)
    {
        return Student::query()
            ->where('stage_id', $this->stage->id)
            ->visibleTo($viewer);
    }
}
