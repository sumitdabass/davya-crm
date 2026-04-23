<?php

namespace App\Dashboard\Cards\Stat;

use App\Dashboard\Card;
use App\Dashboard\DrillDownPayload;
use App\Models\Student;
use App\Models\User;

class LeadsCapturedTodayCard implements Card
{
    public function id(): string { return 'leads_captured_today'; }
    public function label(): string { return 'Leads Captured Today'; }
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
            title: 'Leads Captured Today',
            query: $this->baseQuery($viewer),
            columns: [
                ['key' => 'created_at_time', 'label' => 'Time'],
                ['key' => 'name', 'label' => 'Student'],
                ['key' => 'lead_source', 'label' => 'Source'],
                ['key' => 'owner_name', 'label' => 'Owner'],
            ],
            csvFilenamePrefix: 'leads-captured-today',
        );
    }

    public function viewAllHref(User $viewer): ?string { return null; }

    private function baseQuery(User $viewer)
    {
        return Student::query()
            ->whereBetween('created_at', [now()->startOfDay(), now()->endOfDay()])
            ->visibleTo($viewer);
    }
}
