<?php

namespace App\Dashboard\Cards\ListCards;

use App\Dashboard\Card;
use App\Dashboard\DrillDownPayload;
use App\Models\User;

class ReEntryCandidatesCard implements Card
{
    public function id(): string { return 're_entry_candidates'; }
    public function label(): string { return 'Re-Entry Candidates'; }
    public function surface(): string { return 'any'; }
    public function isDefaultOn(string $surface): bool { return in_array($surface, ['dashboard', 'today'], true); }
    public function type(): string { return 'list'; }

    public function render(User $viewer): string
    {
        return view('filament.widgets.re-entry-candidates-card', ['viewer' => $viewer])->render();
    }

    public function drillDown(User $viewer): ?DrillDownPayload { return null; }
    public function viewAllHref(User $viewer): ?string
    {
        return url(\App\Filters\FilterKeys::studentsListUrl(\App\Filters\FilterKeys::RE_ENTRY));
    }
    public function isAvailableFor(User $viewer): bool { return true; }
}
