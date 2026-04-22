<?php

namespace App\Filament\Widgets;

use App\Models\Payment;
use Filament\Widgets\Widget;
use Illuminate\Support\Carbon;

class TodayPaymentsWidget extends Widget
{
    protected static string $view = 'filament.widgets.today-payments-widget';

    protected int | string | array $columnSpan = 'full';

    /**
     * @return array<int, array{id:int,time:string,student_name:string,student_id:int,amount:float,mode:?string,type:string,owner_name:string}>
     */
    public function getRowsProperty(): array
    {
        $tz = 'Asia/Kolkata';
        $start = Carbon::now($tz)->startOfDay();
        $end   = $start->copy()->addDay();

        return Payment::query()
            ->whereBetween('received_at', [$start, $end->copy()->subSecond()])
            ->whereHas('student', fn ($q) => $q->visibleTo(auth()->user()))
            ->with(['student.owner'])
            ->orderByDesc('received_at')
            ->get()
            ->map(fn (Payment $p) => [
                'id'           => $p->id,
                'time'         => $p->received_at->setTimezone($tz)->format('H:i'),
                'student_name' => $p->student?->name ?? '—',
                'student_id'   => $p->student_id,
                'amount'       => (float) $p->amount,
                'mode'         => $p->mode,
                'type'         => $p->type,
                'owner_name'   => $p->student?->owner?->name ?? '—',
            ])
            ->all();
    }

    public function getTotalProperty(): float
    {
        return array_sum(array_column($this->rows, 'amount'));
    }
}
