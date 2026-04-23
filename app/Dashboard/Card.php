<?php

namespace App\Dashboard;

use App\Models\User;

interface Card
{
    public function id(): string;

    public function label(): string;

    /** 'dashboard' | 'today' | 'any' */
    public function surface(): string;

    public function isDefaultOn(string $surface): bool;

    /** 'stat' | 'list' */
    public function type(): string;

    /** Rendered HTML body (the card frame is added by the page view). */
    public function render(User $viewer): string;

    /** Returns a DrillDownPayload for stat cards; null for list cards. */
    public function drillDown(User $viewer): ?DrillDownPayload;

    /** Optional "View all" deep link for list cards; null if not applicable. */
    public function viewAllHref(User $viewer): ?string;

    /** Whether this card is visible to the given viewer (separate from whether it's on/off in their prefs). */
    public function isAvailableFor(User $viewer): bool;
}
