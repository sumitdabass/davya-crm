<?php

namespace App\Dashboard\Cards\Stat;

use App\Dashboard\Card;
use App\Dashboard\DrillDownPayload;
use App\Models\Student;
use App\Models\User;

class AdmissionsClosedTodayCard implements Card
{
    public function id(): string { return 'admissions_closed_today'; }
    public function label(): string { return 'Admissions Closed Today'; }
    public function surface(): string { return 'any'; }
    public function isDefaultOn(string $surface): bool { return $surface === 'today'; }
    public function type(): string { return 'stat'; }

    public function render(User $viewer): string
    {
        $count = $this->baseQuery($viewer)->count();
        return view('components.dashboard.stat-body', [
            'cardId' => $this->id(),
            'label' => $this->label(),
            'value' => (string) $count,
            'secondary' => null,
            'drillable' => true,
        ])->render();
    }

    public function drillDown(User $viewer): ?DrillDownPayload
    {
        return new DrillDownPayload(
            title: 'Admissions Closed Today',
            query: $this->baseQuery($viewer),
            columns: [
                ['key' => 'updated_at_time', 'label' => 'Time'],
                ['key' => 'name', 'label' => 'Student'],
                ['key' => 'course', 'label' => 'Course'],
                ['key' => 'final_college', 'label' => 'Final college'],
                ['key' => 'owner_name', 'label' => 'Owner'],
            ],
            csvFilenamePrefix: 'admissions-closed-today',
        );
    }

    public function viewAllHref(User $viewer): ?string { return null; }
    public function isAvailableFor(User $viewer): bool { return true; }

    private function baseQuery(User $viewer)
    {
        return Student::query()
            ->where('stage', 'Closed')
            ->where('close_reason', 'Completed')
            ->whereBetween('updated_at', [now()->startOfDay(), now()->endOfDay()])
            ->visibleTo($viewer);
    }
}
