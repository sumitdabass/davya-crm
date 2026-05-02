<?php

namespace App\Filament\Pages;

use App\Models\Payment;
use App\Models\User;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PaymentReport extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-chart-pie';

    protected static ?string $navigationLabel = 'Payment report';

    protected static ?string $navigationGroup = 'Reports';

    protected static string $view = 'filament.pages.payment-report';

    protected static ?string $slug = 'payments-report';

    protected static ?string $title = 'Payment Report';

    public ?array $data = [];

    public ?array $applied = [];

    public string $activeTab = 'report';

    public function mount(): void
    {
        $defaults = [
            'from' => now('Asia/Kolkata')->startOfMonth()->toDateString(),
            'to'   => now('Asia/Kolkata')->endOfDay()->toDateString(),
            'owner_id' => null,
        ];
        $this->form->fill($defaults);
        $this->applied = $defaults;
        $this->activeTab = 'report';
    }

    public function apply(): void
    {
        $this->applied = $this->form->getState();
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                DatePicker::make('from')->label('From')->required()->native(false),
                DatePicker::make('to')->label('To')->required()->native(false),
                Select::make('owner_id')->label('Owner (optional)')
                    ->options(fn () => $this->ownerOptions())
                    ->searchable()
                    ->nullable(),
            ])
            ->columns(['default' => 1, 'md' => 3])
            ->statePath('data');
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->hasRole(['admin', 'head']) ?? false;
    }

    /** @return array<int,string> */
    protected function ownerOptions(): array
    {
        $user = auth()->user();
        if ($user === null) {
            return [];
        }
        if ($user->hasRole('admin')) {
            return User::orderBy('name')->pluck('name', 'id')->all();
        }
        // Head: own team members (including self).
        return User::where('id', $user->id)
            ->orWhere('team_head_id', $user->id)
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    /**
     * @return array{totals:array<string,float>, byOwner:array<int,array{name:string,received:float,refunds:float,count:int}>, byType:array<string,float>}
     */
    public function getReport(): array
    {
        $filters = ! empty($this->applied) ? $this->applied : $this->data;

        $from = Carbon::parse($filters['from'] ?? now()->startOfMonth(), 'Asia/Kolkata')->startOfDay();
        $to   = Carbon::parse($filters['to']   ?? now(),                     'Asia/Kolkata')->endOfDay();
        $ownerId = $filters['owner_id'] ?? null;

        $user = auth()->user();

        $base = Payment::query()
            ->whereBetween('received_at', [$from, $to])
            ->whereHas('student', fn ($q) => $q->visibleTo($user));

        if ($ownerId) {
            $base->whereHas('student', fn ($q) => $q->where('owner_id', $ownerId));
        }

        $totalReceived = (float) (clone $base)->where('amount', '>', 0)->sum('amount');
        $totalRefunds  = (float) (clone $base)->where('amount', '<', 0)->sum('amount');
        $netCollected  = $totalReceived + $totalRefunds; // refunds negative

        $byOwner = [];
        foreach (User::orderBy('name')->get() as $u) {
            $received = (float) (clone $base)
                ->where('amount', '>', 0)
                ->whereHas('student', fn ($q) => $q->where('owner_id', $u->id))
                ->sum('amount');
            $refunds = (float) (clone $base)
                ->where('amount', '<', 0)
                ->whereHas('student', fn ($q) => $q->where('owner_id', $u->id))
                ->sum('amount');
            $count = (int) (clone $base)
                ->whereHas('student', fn ($q) => $q->where('owner_id', $u->id))
                ->count();
            if ($received == 0.0 && $refunds == 0.0 && $count === 0) {
                continue;
            }
            $byOwner[$u->id] = [
                'name'     => $u->name,
                'received' => $received,
                'refunds'  => $refunds,
                'count'    => $count,
            ];
        }

        $byType = [];
        foreach (['advance', 'partial', 'full', 'refund'] as $type) {
            $byType[$type] = (float) (clone $base)->where('type', $type)->sum('amount');
        }

        return [
            'totals' => [
                'received' => $totalReceived,
                'refunds'  => $totalRefunds,
                'net'      => $netCollected,
                'count'    => (int) (clone $base)->count(),
            ],
            'byOwner' => $byOwner,
            'byType'  => $byType,
        ];
    }

    public function setTab(string $tab): void
    {
        $this->activeTab = in_array($tab, ['report', 'today'], true) ? $tab : 'report';
    }

    /**
     * @return array<int, array{id:int,time:string,student_name:string,student_id:int,amount:float,mode:?string,type:string,owner_name:string}>
     */
    public function getTodayRowsProperty(): array
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

    public function downloadTodayCsv(): StreamedResponse
    {
        $rows = $this->todayRows;
        $filename = 'payments-today-'.now('Asia/Kolkata')->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($rows): void {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Time', 'Student', 'Amount', 'Mode', 'Type', 'Owner']);
            foreach ($rows as $r) {
                fputcsv($out, [
                    $r['time'], $r['student_name'],
                    number_format($r['amount'], 2, '.', ''),
                    $r['mode'] ?? '', $r['type'], $r['owner_name'],
                ]);
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }
}
