<?php

namespace App\Dashboard\Cards\ListCards;

use App\Dashboard\Card;
use App\Dashboard\DrillDownPayload;
use App\Models\User;

class SeatFeePendingCard implements Card
{
    public function id(): string { return 'seat_fee_pending'; }
    public function label(): string { return 'Seat Fee Pending'; }
    public function surface(): string { return 'any'; }
    public function isDefaultOn(string $surface): bool { return $surface === 'dashboard'; }
    public function type(): string { return 'list'; }

    public function render(User $viewer): string
    {
        return view('filament.widgets.seat-fee-pending-card', ['viewer' => $viewer])->render();
    }

    public function drillDown(User $viewer): ?DrillDownPayload { return null; }
    public function viewAllHref(User $viewer): ?string
    {
        return route('filament.admin.resources.students.index').'?tableFilters[seat_fee_pending][isActive]=1';
    }
    public function isAvailableFor(User $viewer): bool { return true; }
}
