<?php

namespace App\Dashboard\Cards\Stat;

use App\Dashboard\Card;
use App\Dashboard\DrillDownPayload;
use App\Models\Meeting;
use App\Models\Student;
use App\Models\User;

class TeamStatCard implements Card
{
    public const METRIC_LEADS_CAPTURED = 'leads_captured';
    public const METRIC_MEETINGS_HELD = 'meetings_held';
    public const METRIC_ADMISSIONS_CLOSED = 'admissions_closed';

    public const METRIC_LABELS = [
        self::METRIC_LEADS_CAPTURED => 'Leads Captured Today',
        self::METRIC_MEETINGS_HELD => 'Meetings Held Today',
        self::METRIC_ADMISSIONS_CLOSED => 'Admissions Closed Today',
    ];

    public function __construct(
        private readonly User $head,
        private readonly string $metric,
    ) {}

    public function id(): string
    {
        return 'team.'.$this->head->id.'.'.$this->metric;
    }

    public function label(): string
    {
        return $this->head->name.' — '.self::METRIC_LABELS[$this->metric];
    }

    public function surface(): string { return 'dashboard'; }
    public function isDefaultOn(string $surface): bool { return false; }  // opt-in only
    public function type(): string { return 'stat'; }

    public function isAvailableFor(User $viewer): bool
    {
        if ($viewer->hasRole('admin')) {
            return true;
        }
        if ($viewer->hasRole('head') && (int) $viewer->id === (int) $this->head->id) {
            return true;
        }
        return false;
    }

    public function render(User $viewer): string
    {
        $count = $this->baseQuery()->count();
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
        $columns = match ($this->metric) {
            self::METRIC_LEADS_CAPTURED => [
                ['key' => 'created_at_time', 'label' => 'Time'],
                ['key' => 'name', 'label' => 'Student'],
                ['key' => 'lead_source', 'label' => 'Source'],
                ['key' => 'owner_name', 'label' => 'Owner'],
            ],
            self::METRIC_MEETINGS_HELD => [
                ['key' => 'held_at_time', 'label' => 'Time held'],
                ['key' => 'student_name', 'label' => 'Student'],
                ['key' => 'course', 'label' => 'Course'],
                ['key' => 'owner_name', 'label' => 'Owner'],
            ],
            self::METRIC_ADMISSIONS_CLOSED => [
                ['key' => 'updated_at_time', 'label' => 'Time'],
                ['key' => 'name', 'label' => 'Student'],
                ['key' => 'course', 'label' => 'Course'],
                ['key' => 'final_college', 'label' => 'Final college'],
                ['key' => 'owner_name', 'label' => 'Owner'],
            ],
        };

        return new DrillDownPayload(
            title: $this->label(),
            query: $this->baseQuery(),
            columns: $columns,
            csvFilenamePrefix: 'team-'.str($this->head->name)->slug()->toString().'-'.$this->metric,
        );
    }

    public function viewAllHref(User $viewer): ?string { return null; }

    /** @return int[] */
    private function teamUserIds(): array
    {
        return [
            $this->head->id,
            ...$this->head->teamMembers()->pluck('id')->all(),
        ];
    }

    private function baseQuery()
    {
        $teamIds = $this->teamUserIds();
        $todayRange = [now()->startOfDay(), now()->endOfDay()];

        return match ($this->metric) {
            self::METRIC_LEADS_CAPTURED => Student::query()
                ->whereBetween('created_at', $todayRange)
                ->whereIn('owner_id', $teamIds),

            self::METRIC_MEETINGS_HELD => Meeting::query()
                ->where('status', 'held')
                ->whereBetween('held_at', $todayRange)
                ->whereHas('student', fn ($q) => $q->whereIn('owner_id', $teamIds)),

            self::METRIC_ADMISSIONS_CLOSED => Student::query()
                ->where('stage', 'Closed')
                ->where('close_reason', 'Completed')
                ->whereBetween('updated_at', $todayRange)
                ->whereIn('owner_id', $teamIds)
                ->with(['latestAdmittedRound']),
        };
    }
}
