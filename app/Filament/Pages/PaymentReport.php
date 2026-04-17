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

    public function mount(): void
    {
        $this->form->fill([
            'from' => now('Asia/Kolkata')->startOfMonth()->toDateString(),
            'to'   => now('Asia/Kolkata')->endOfDay()->toDateString(),
            'owner_id' => null,
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                DatePicker::make('from')->label('From')->required()->native(false),
                DatePicker::make('to')->label('To')->required()->native(false),
                Select::make('owner_id')->label('Owner (optional)')
                    ->options(fn () => User::orderBy('name')->pluck('name', 'id'))
                    ->searchable()
                    ->nullable(),
            ])
            ->columns(3)
            ->statePath('data');
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->hasRole(['admin', 'head']) ?? false;
    }

    /**
     * @return array{totals:array<string,float>, byOwner:array<int,array{name:string,received:float,refunds:float,count:int}>, byType:array<string,float>}
     */
    public function getReport(): array
    {
        $from = Carbon::parse($this->data['from'] ?? now()->startOfMonth(), 'Asia/Kolkata')->startOfDay();
        $to   = Carbon::parse($this->data['to']   ?? now(),                     'Asia/Kolkata')->endOfDay();
        $ownerId = $this->data['owner_id'] ?? null;

        $base = Payment::query()
            ->whereBetween('received_at', [$from, $to]);

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
}
