<?php

namespace App\Dashboard\Cards\ListCards;

use App\Dashboard\Card;
use App\Dashboard\DrillDownPayload;
use App\Models\Student;
use App\Models\User;
use Illuminate\Contracts\Database\Eloquent\Builder;

class PaymentsToChaseCard implements Card
{
    public function id(): string { return 'payments_to_chase'; }
    public function label(): string { return 'Payments to Chase'; }
    public function surface(): string { return 'any'; }
    public function isDefaultOn(string $surface): bool { return $surface === 'today'; }
    public function type(): string { return 'list'; }

    /** Students with a positive pending balance who are not closed. */
    public function query(User $viewer): Builder
    {
        return Student::query()
            ->visibleTo($viewer)
            ->where('deal_amount', '>', 0)
            ->whereNotIn('stage', ['Closed'])
            ->whereRaw('students.deal_amount > (SELECT COALESCE(SUM(amount), 0) FROM payments WHERE payments.student_id = students.id)')
            ->with('owner')
            ->orderByDesc('updated_at');
    }

    public function render(User $viewer): string
    {
        // Not used on the Today checklist (rows come from ChecklistSections),
        // but the Card contract requires it; render a minimal list for any
        // future Dashboard use.
        $rows = $this->query($viewer)->limit(10)->get();

        return view('filament.widgets.payments-to-chase-card', ['rows' => $rows])->render();
    }

    public function drillDown(User $viewer): ?DrillDownPayload { return null; }
    public function viewAllHref(User $viewer): ?string { return null; }
    public function isAvailableFor(User $viewer): bool { return true; }
}
