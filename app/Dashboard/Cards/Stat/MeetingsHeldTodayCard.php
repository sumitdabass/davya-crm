<?php

namespace App\Dashboard\Cards\Stat;

use App\Dashboard\Card;
use App\Dashboard\DrillDownPayload;
use App\Models\Meeting;
use App\Models\User;

class MeetingsHeldTodayCard implements Card
{
    public function id(): string { return 'meetings_held_today'; }
    public function label(): string { return 'Meetings Held Today'; }
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
            title: 'Meetings Held Today',
            query: $this->baseQuery($viewer),
            columns: [
                ['key' => 'held_at_time', 'label' => 'Time held'],
                ['key' => 'student_name', 'label' => 'Student'],
                ['key' => 'course', 'label' => 'Course'],
                ['key' => 'owner_name', 'label' => 'Owner'],
            ],
            csvFilenamePrefix: 'meetings-held-today',
        );
    }

    public function viewAllHref(User $viewer): ?string { return null; }

    private function baseQuery(User $viewer)
    {
        return Meeting::query()
            ->where('status', 'held')
            ->whereBetween('held_at', [now()->startOfDay(), now()->endOfDay()])
            ->whereHas('student', fn ($q) => $q->visibleTo($viewer));
    }
}
