<?php

namespace App\Filament\Widgets;

use App\Models\Meeting;
use App\Models\Student;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Widgets\Widget;
use Illuminate\Support\Carbon;

class TodayMeetingsWidget extends Widget implements HasActions, HasForms
{
    use InteractsWithActions;
    use InteractsWithForms;

    protected static string $view = 'filament.widgets.today-meetings-widget';

    protected int | string | array $columnSpan = 'full';

    /**
     * @return array<int, array{
     *     date:\Illuminate\Support\Carbon,
     *     label:string,
     *     is_today:bool,
     *     meetings:array<int, array{id:int,time:string,student_name:string,student_phone:?string,course:?string,mode:string,owner_initials:string,status:string,is_overdue:bool}>
     * }>
     */
    public function getDaysProperty(): array
    {
        $tz = 'Asia/Kolkata';
        $start = Carbon::now($tz)->startOfDay();
        $end   = $start->copy()->addDays(5);

        $meetings = Meeting::query()
            ->visibleTo(auth()->user())
            ->whereBetween('scheduled_at', [$start, $end->copy()->subSecond()])
            ->whereIn('status', ['scheduled', 'held'])
            ->with(['student', 'owner'])
            ->orderBy('scheduled_at')
            ->get();

        $days = [];
        for ($i = 0; $i < 5; $i++) {
            $dayStart = $start->copy()->addDays($i);
            $dayEnd   = $dayStart->copy()->addDay();

            $slot = $meetings->filter(fn (Meeting $m) => $m->scheduled_at->between($dayStart, $dayEnd->copy()->subSecond()))->values();

            $days[] = [
                'date'     => $dayStart,
                'label'    => $i === 0 ? 'Today' : $dayStart->format('D j M'),
                'is_today' => $i === 0,
                'meetings' => $slot->map(fn (Meeting $m) => [
                    'id'             => $m->id,
                    'time'           => $m->scheduled_at->setTimezone($tz)->format('H:i'),
                    'student_name'   => $m->student?->name ?? '—',
                    'student_phone'  => $m->student?->phone,
                    'course'         => $m->student?->course,
                    'mode'           => $m->mode,
                    'owner_initials' => $this->initials($m->owner?->name ?? '?'),
                    'status'         => $m->status,
                    'is_overdue'     => $m->status === 'scheduled' && $m->scheduled_at->lt(Carbon::now($tz)),
                ])->all(),
            ];
        }
        return $days;
    }

    private function initials(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];
        $first = mb_substr($parts[0] ?? '?', 0, 1);
        $last  = count($parts) > 1 ? mb_substr(end($parts), 0, 1) : '';
        return strtoupper($first . $last);
    }

    public function scheduleAction(): Action
    {
        return Action::make('schedule')
            ->label('+ Schedule')
            ->icon('heroicon-o-plus')
            ->size('xs')
            ->form([
                Select::make('student_id')
                    ->label('Student')
                    ->options(fn () => Student::query()
                        ->visibleTo(auth()->user())
                        ->whereNotIn('stage', ['Admission Confirmed', 'Closed'])
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all())
                    ->searchable()
                    ->required(),
                DateTimePicker::make('scheduled_at')
                    ->required()
                    ->native(false)
                    ->default(fn () => now('Asia/Kolkata')->addHour()->startOfHour()),
                Select::make('mode')
                    ->options([
                        'in_person' => 'In person',
                        'phone'     => 'Phone',
                        'video'     => 'Video',
                        'whatsapp'  => 'WhatsApp',
                    ])
                    ->default('in_person')
                    ->required(),
                Textarea::make('notes')->rows(2),
            ])
            ->action(function (array $data): void {
                $student = Student::query()
                    ->visibleTo(auth()->user())
                    ->findOrFail($data['student_id']);

                Meeting::create([
                    'student_id'    => $student->id,
                    'owner_id'      => $student->owner_id ?? auth()->id(),
                    'scheduled_at'  => $data['scheduled_at'],
                    'mode'          => $data['mode'],
                    'status'        => 'scheduled',
                    'notes'         => $data['notes'] ?? null,
                    'created_by_id' => auth()->id(),
                ]);
            });
    }
}
