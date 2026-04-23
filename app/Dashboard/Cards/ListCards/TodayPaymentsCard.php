<?php

namespace App\Dashboard\Cards\ListCards;

use App\Dashboard\Card;
use App\Dashboard\DrillDownPayload;
use App\Models\User;

class TodayPaymentsCard implements Card
{
    public function id(): string { return 'today_payments'; }
    public function label(): string { return 'Today Payments'; }
    public function surface(): string { return 'any'; }
    public function isDefaultOn(string $surface): bool { return $surface === 'today'; }
    public function type(): string { return 'list'; }

    public function render(User $viewer): string
    {
        return view('filament.widgets.today-payments-card', ['viewer' => $viewer])->render();
    }

    public function drillDown(User $viewer): ?DrillDownPayload { return null; }
    public function viewAllHref(User $viewer): ?string
    {
        return route('filament.admin.pages.payments-report').'?activeTab=today';
    }
    public function isAvailableFor(User $viewer): bool { return true; }
}
