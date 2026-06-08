<?php

namespace App\Filament\Pages;

use App\Models\Payment;
use App\Models\Payout;
use App\Models\Student;
use App\Models\User;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Url;
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

    #[Url(except: 'report')]
    public string $activeTab = 'report';

    #[Url(as: 'owner', except: null)]
    public ?int $detailOwnerId = null;

    #[Url(as: 'type', except: null)]
    public ?string $detailType = null;

    #[Url(as: 'from', except: '')]
    public string $urlFrom = '';

    #[Url(as: 'to', except: '')]
    public string $urlTo = '';

    /** @var array<int,int> */
    #[Url(as: 'owners', except: [])]
    public array $urlOwnerIds = [];

    public function mount(): void
    {
        $today = now('Asia/Kolkata');
        $from = $this->urlFrom !== '' ? $this->urlFrom : $today->copy()->startOfMonth()->toDateString();
        $to = $this->urlTo !== '' ? $this->urlTo : $today->endOfDay()->toDateString();

        $this->applied = [
            'from' => $from,
            'to' => $to,
            'owner_ids' => $this->urlOwnerIds,
        ];
        $this->form->fill($this->applied);

        // Validate URL-bound values that could be tampered with.
        if (! in_array($this->activeTab, ['report', 'today', 'detail'], true)) {
            $this->activeTab = 'report';
        }
        if ($this->detailType !== null && ! in_array($this->detailType, ['advance', 'partial', 'full', 'refund'], true)) {
            $this->detailType = null;
        }
    }

    public function apply(): void
    {
        $state = $this->form->getState();
        $this->urlFrom = $state['from'] ?? '';
        $this->urlTo = $state['to'] ?? '';
        $this->urlOwnerIds = array_values(array_filter(
            (array) ($state['owner_ids'] ?? []),
            fn ($v) => $v !== null && $v !== '',
        ));
        $this->applied = $state;
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                DatePicker::make('from')->label('From')->required()->native(false),
                DatePicker::make('to')->label('To')->required()->native(false),
                Select::make('owner_ids')->label('Owners')
                    ->options(fn () => $this->ownerOptions())
                    ->multiple()
                    ->searchable()
                    ->preload()
                    ->placeholder('All owners'),
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
     * @return array{totals:array<string,float>, byOwner:array<int,array{name:string,received:float,refunds:float,count:int,expected_profit:float}>, byType:array<string,float>, profit:array<string,float>}
     */
    public function getReport(): array
    {
        $filters = ! empty($this->applied) ? $this->applied : $this->data;

        $from = Carbon::parse($filters['from'] ?? now()->startOfMonth(), 'Asia/Kolkata')->startOfDay();
        $to = Carbon::parse($filters['to'] ?? now(), 'Asia/Kolkata')->endOfDay();
        $ownerIds = array_values(array_filter((array) ($filters['owner_ids'] ?? []), fn ($v) => $v !== null && $v !== ''));

        $user = auth()->user();

        $base = Payment::query()
            ->whereBetween('received_at', [$from, $to])
            ->whereHas('student', fn ($q) => $q->visibleTo($user));

        if (! empty($ownerIds)) {
            $base->whereHas('student', fn ($q) => $q->whereIn('owner_id', $ownerIds));
        }

        $totalReceived = (float) (clone $base)->where('amount', '>', 0)->sum('amount');
        $totalRefunds = (float) (clone $base)->where('amount', '<', 0)->sum('amount');
        $netCollected = $totalReceived + $totalRefunds; // refunds negative

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
            $ownerStudentIds = Student::query()
                ->visibleTo($user)
                ->where('owner_id', $u->id)
                ->whereBetween('created_at', [$from, $to])
                ->pluck('id');
            $ownerDeal = (float) Student::whereIn('id', $ownerStudentIds)->sum('deal_amount');
            $ownerCommitted = (float) Payout::whereIn('student_id', $ownerStudentIds)->sum('amount');
            $ownerProfit = $ownerDeal - $ownerCommitted;
            $byOwner[$u->id] = [
                'name' => $u->name,
                'received' => $received,
                'refunds' => $refunds,
                'count' => $count,
                'expected_profit' => $ownerProfit,
            ];
        }

        $byType = [];
        foreach (['advance', 'partial', 'full', 'refund'] as $type) {
            $byType[$type] = (float) (clone $base)->where('type', $type)->sum('amount');
        }

        $studentBase = Student::query()
            ->visibleTo($user)
            ->whereBetween('created_at', [$from, $to]);
        if (! empty($ownerIds)) {
            $studentBase->whereIn('owner_id', $ownerIds);
        }

        $totalDeal = (float) (clone $studentBase)->sum('deal_amount');
        $studentIds = (clone $studentBase)->pluck('id');
        $committedPayouts = (float) Payout::whereIn('student_id', $studentIds)->sum('amount');
        $paidPayouts = (float) Payout::whereIn('student_id', $studentIds)->where('status', 'paid')->sum('amount');

        return [
            'totals' => [
                'received' => $totalReceived,
                'refunds' => $totalRefunds,
                'net' => $netCollected,
                'count' => (int) (clone $base)->count(),
            ],
            'byOwner' => $byOwner,
            'byType' => $byType,
            'profit' => [
                'total_deal' => $totalDeal,
                'committed' => $committedPayouts,
                'paid_out' => $paidPayouts,
                'expected_profit' => $totalDeal - $committedPayouts,
                'outstanding' => $committedPayouts - $paidPayouts,
            ],
        ];
    }

    /**
     * Sparkline series + period-over-period delta per KPI tile.
     * - series: daily cumulative running totals across the current filter range
     *   (≥ 2 buckets — pads to two points if the range is one day so the spark
     *   still renders as a flat line).
     * - delta_pct: vs the immediately preceding range of the same length.
     *
     * @return array<string, array{series: array<int,float>, delta_pct: ?float, prior_label: ?string}>
     */
    public function getKpiMeta(): array
    {
        $filters = ! empty($this->applied) ? $this->applied : $this->data;

        $from = Carbon::parse($filters['from'] ?? now()->startOfMonth(), 'Asia/Kolkata')->startOfDay();
        $to = Carbon::parse($filters['to'] ?? now(), 'Asia/Kolkata')->endOfDay();
        $ownerIds = array_values(array_filter((array) ($filters['owner_ids'] ?? []), fn ($v) => $v !== null && $v !== ''));
        $user = auth()->user();

        $rangeDays = max(1, $from->copy()->startOfDay()->diffInDays($to->copy()->startOfDay()) + 1);
        $priorTo = $from->copy()->subSecond();
        $priorFrom = $priorTo->copy()->subDays($rangeDays - 1)->startOfDay();

        $buildSeries = function (callable $scope, Carbon $rangeFrom, Carbon $rangeTo) use ($ownerIds, $user): array {
            $base = Payment::query()
                ->whereBetween('received_at', [$rangeFrom, $rangeTo])
                ->whereHas('student', fn ($q) => $q->visibleTo($user));
            if (! empty($ownerIds)) {
                $base->whereHas('student', fn ($q) => $q->whereIn('owner_id', $ownerIds));
            }
            $scope($base);
            $driver = $base->getModel()->getConnection()->getDriverName();
            $expr = $driver === 'sqlite'
                ? "strftime('%Y-%m-%d', received_at)"
                : 'DATE(received_at)';
            $rows = $base->selectRaw("{$expr} AS d, SUM(amount) AS total, COUNT(*) AS c")
                ->groupBy('d')->orderBy('d')
                ->pluck('total', 'd')->map(fn ($v) => (float) $v)->all();

            $buckets = [];
            $cum = 0.0;
            $cursor = $rangeFrom->copy()->startOfDay();
            while ($cursor->lessThanOrEqualTo($rangeTo)) {
                $key = $cursor->format('Y-m-d');
                $cum += $rows[$key] ?? 0.0;
                $buckets[] = $cum;
                $cursor->addDay();
            }

            return $buckets ?: [0.0, 0.0];
        };

        $buildCountSeries = function (Carbon $rangeFrom, Carbon $rangeTo) use ($ownerIds, $user): array {
            $base = Payment::query()
                ->whereBetween('received_at', [$rangeFrom, $rangeTo])
                ->whereHas('student', fn ($q) => $q->visibleTo($user));
            if (! empty($ownerIds)) {
                $base->whereHas('student', fn ($q) => $q->whereIn('owner_id', $ownerIds));
            }
            $driver = $base->getModel()->getConnection()->getDriverName();
            $expr = $driver === 'sqlite'
                ? "strftime('%Y-%m-%d', received_at)"
                : 'DATE(received_at)';
            $rows = $base->selectRaw("{$expr} AS d, COUNT(*) AS c")
                ->groupBy('d')->orderBy('d')
                ->pluck('c', 'd')->map(fn ($v) => (int) $v)->all();

            $buckets = [];
            $cum = 0;
            $cursor = $rangeFrom->copy()->startOfDay();
            while ($cursor->lessThanOrEqualTo($rangeTo)) {
                $key = $cursor->format('Y-m-d');
                $cum += (int) ($rows[$key] ?? 0);
                $buckets[] = (float) $cum;
                $cursor->addDay();
            }

            return $buckets ?: [0.0, 0.0];
        };

        // Final totals for delta math — sum of the series == last cumulative value.
        $currentReceived = $buildSeries(fn ($q) => $q->where('amount', '>', 0), $from, $to);
        $priorReceived = $buildSeries(fn ($q) => $q->where('amount', '>', 0), $priorFrom, $priorTo);
        $currentRefunds = array_map('abs', $buildSeries(fn ($q) => $q->where('amount', '<', 0), $from, $to));
        $priorRefunds = array_map('abs', $buildSeries(fn ($q) => $q->where('amount', '<', 0), $priorFrom, $priorTo));
        $currentNet = $buildSeries(fn ($q) => $q, $from, $to);
        $priorNet = $buildSeries(fn ($q) => $q, $priorFrom, $priorTo);
        $currentCount = $buildCountSeries($from, $to);
        $priorCount = $buildCountSeries($priorFrom, $priorTo);

        $delta = function (array $now, array $then): ?float {
            $a = end($now);
            $b = end($then);
            if ($b === false || abs((float) $b) < 0.01) {
                return null;
            }

            return (((float) $a - (float) $b) / abs((float) $b)) * 100.0;
        };
        $priorLabel = $priorFrom->format('d M').'–'.$priorTo->format('d M');

        return [
            'received' => ['series' => $currentReceived, 'delta_pct' => $delta($currentReceived, $priorReceived), 'prior_label' => $priorLabel],
            'refunds' => ['series' => $currentRefunds,  'delta_pct' => $delta($currentRefunds, $priorRefunds),   'prior_label' => $priorLabel],
            'net' => ['series' => $currentNet,      'delta_pct' => $delta($currentNet, $priorNet),           'prior_label' => $priorLabel],
            'count' => ['series' => $currentCount,    'delta_pct' => $delta($currentCount, $priorCount),       'prior_label' => $priorLabel],
        ];
    }

    public function setTab(string $tab, ?int $ownerId = null, ?string $type = null): void
    {
        $this->activeTab = in_array($tab, ['report', 'today', 'detail'], true) ? $tab : 'report';

        if ($this->activeTab === 'detail') {
            $this->detailOwnerId = $ownerId;
            $this->detailType = in_array($type, ['advance', 'partial', 'full', 'refund'], true) ? $type : null;
        } else {
            $this->detailOwnerId = null;
            $this->detailType = null;
        }
    }

    /**
     * @return array<int, array{id:int,received_at:string,student_name:string,student_id:int,amount:float,mode:?string,type:string,owner_name:string}>
     */
    public function getDetailRows(): array
    {
        $filters = ! empty($this->applied) ? $this->applied : $this->data;

        $tz = 'Asia/Kolkata';
        $from = Carbon::parse($filters['from'] ?? now()->startOfMonth(), $tz)->startOfDay();
        $to = Carbon::parse($filters['to'] ?? now(), $tz)->endOfDay();
        $ownerIds = array_values(array_filter((array) ($filters['owner_ids'] ?? []), fn ($v) => $v !== null && $v !== ''));

        $q = Payment::query()
            ->whereBetween('received_at', [$from, $to])
            ->whereHas('student', fn ($q) => $q->visibleTo(auth()->user()))
            ->with(['student.owner'])
            ->orderByDesc('received_at');

        if (! empty($ownerIds)) {
            $q->whereHas('student', fn ($q) => $q->whereIn('owner_id', $ownerIds));
        }
        if ($this->detailOwnerId !== null) {
            $q->whereHas('student', fn ($q) => $q->where('owner_id', $this->detailOwnerId));
        }
        if ($this->detailType !== null) {
            $q->where('type', $this->detailType);
        }

        return $q->get()->map(fn (Payment $p) => [
            'id' => $p->id,
            'received_at' => $p->received_at->setTimezone($tz)->format('d M, H:i'),
            'student_name' => $p->student?->name ?? '—',
            'student_id' => $p->student_id,
            'amount' => (float) $p->amount,
            'mode' => $p->mode,
            'type' => $p->type,
            'owner_name' => $p->student?->owner?->name ?? '—',
        ])->all();
    }

    public function getDetailScopeLabel(): string
    {
        $bits = [];
        if ($this->detailOwnerId !== null) {
            $bits[] = User::find($this->detailOwnerId)?->name ?? 'owner';
        }
        if ($this->detailType !== null) {
            $bits[] = ucfirst($this->detailType);
        }

        return $bits ? implode(' · ', $bits) : 'All payments in range';
    }

    /**
     * @return array<int, array{id:int,time:string,student_name:string,student_id:int,amount:float,mode:?string,type:string,owner_name:string}>
     */
    public function getTodayRowsProperty(): array
    {
        $tz = 'Asia/Kolkata';
        $start = Carbon::now($tz)->startOfDay();
        $end = $start->copy()->addDay();

        return Payment::query()
            ->whereBetween('received_at', [$start, $end->copy()->subSecond()])
            ->whereHas('student', fn ($q) => $q->visibleTo(auth()->user()))
            ->with(['student.owner'])
            ->orderByDesc('received_at')
            ->get()
            ->map(fn (Payment $p) => [
                'id' => $p->id,
                'time' => $p->received_at->setTimezone($tz)->format('H:i'),
                'student_name' => $p->student?->name ?? '—',
                'student_id' => $p->student_id,
                'amount' => (float) $p->amount,
                'mode' => $p->mode,
                'type' => $p->type,
                'owner_name' => $p->student?->owner?->name ?? '—',
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
