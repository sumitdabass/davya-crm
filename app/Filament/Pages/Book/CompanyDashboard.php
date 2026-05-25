<?php

namespace App\Filament\Pages\Book;

use App\Books\Services\ClosingSnapshotWriter;
use App\Books\Services\DepreciationCalculator;
use App\Books\Services\FiscalYearAggregator;
use App\Models\Book\Asset;
use App\Models\Book\Company;
use App\Models\Book\Entry;
use App\Models\Book\EntryPayment;
use App\Models\Book\FiscalYear;
use App\Models\Book\Section;
use App\Support\MoneyFormat;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Pages\Page;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;

class CompanyDashboard extends Page
{
    protected static ?string $slug = 'books/{company}/{fy}';

    protected static bool $shouldRegisterNavigation = false;

    protected static string $view = 'filament.pages.book.company-dashboard';

    public const DASHBOARD_REGIONS = [
        'balance' => 'Balance Available',
        'kpis' => 'KPI Tiles',
        'rollups' => 'Section Roll-ups',
        'assets' => 'Asset Register',
        'loans' => 'Loans Outstanding',
    ];

    /** @var Company */
    public $company;

    /** @var FiscalYear */
    public $fy;

    public function mount(string $company, string $fy): void
    {
        abort_unless(config('books.enabled'), 404);
        abort_unless(auth()->user()?->isSuperAdmin(), 403);

        $this->company = Company::where('slug', $company)->firstOrFail();
        $this->fy = FiscalYear::where('company_id', $this->company->id)
            ->where('label', $fy)
            ->firstOrFail();
    }

    /**
     * All FYs for the current company, newest first — feeds the header
     * year-switcher dropdown so super_admins can hop between years without
     * detouring through the Companies landing.
     *
     * @return Collection<int, FiscalYear>
     */
    public function companyFiscalYears(): Collection
    {
        return FiscalYear::where('company_id', $this->company->id)
            ->orderByDesc('start_date')
            ->get();
    }

    public function getVisibleRegions(): array
    {
        $prefs = auth()->user()?->books_dashboard_prefs ?? null;
        if (! is_array($prefs)) {
            return array_fill_keys(array_keys(self::DASHBOARD_REGIONS), true);
        }
        // Default any missing key to true (so newly added regions are visible)
        $resolved = [];
        foreach (array_keys(self::DASHBOARD_REGIONS) as $key) {
            $resolved[$key] = $prefs[$key] ?? true;
        }

        return $resolved;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('cashReceived')
                ->label('+ Cash Received')
                ->icon('heroicon-o-banknotes')
                ->color('success')
                ->visible(fn () => ! $this->fy->is_closed)
                ->fillForm(fn () => [
                    'source' => 'Other',
                    'amount' => null,
                    'occurred_on' => now()->toDateString(),
                    'mode' => 'bank',
                ])
                ->form([
                    TextInput::make('source')
                        ->label('Source')
                        ->required()
                        ->default('Other')
                        ->placeholder('Free text — e.g. "Refund", "Sumit Loan back", "Other"'),
                    TextInput::make('amount')
                        ->label('Amount')
                        ->required()
                        ->numeric()
                        ->minValue(0.01)
                        ->prefix('₹')
                        ->live(onBlur: true)
                        ->helperText(fn ($state) => $state
                            ? MoneyFormat::toIndianWords((float) $state)
                            : 'Type an amount — the words will appear here.'),
                    DatePicker::make('occurred_on')
                        ->label('Date')
                        ->required(),
                    Select::make('mode')
                        ->required()
                        ->options([
                            'cash' => 'Cash',
                            'bank' => 'Bank transfer',
                            'upi' => 'UPI',
                            'cheque' => 'Cheque',
                            'other' => 'Other',
                        ]),
                    TextInput::make('reference')
                        ->label('Reference')
                        ->placeholder('e.g. cheque no., UTR, txn id'),
                    Textarea::make('notes')
                        ->label('Notes')
                        ->rows(2)
                        ->placeholder('Optional — any context that helps later reconciliation'),
                ])
                ->action(function (array $data): void {
                    if ($this->fy->is_closed) {
                        throw new \DomainException("Cannot record receipt — FY {$this->fy->label} is closed");
                    }
                    $section = $this->company->sections()->where('slug', 'receipts')->first();
                    if (! $section) {
                        $maxOrder = (int) $this->company->sections()->max('sort_order');
                        $section = Section::create([
                            'company_id' => $this->company->id,
                            'slug' => 'receipts',
                            'name' => 'Receipts',
                            'kind' => 'generic',
                            'sort_order' => $maxOrder + 1,
                        ]);
                    }
                    $source = trim((string) ($data['source'] ?? '')) ?: 'Other';
                    $entry = Entry::create([
                        'company_id' => $this->company->id,
                        'fiscal_year_id' => $this->fy->id,
                        'section_id' => $section->id,
                        'title' => $source,
                    ]);
                    EntryPayment::create([
                        'entry_id' => $entry->id,
                        'occurred_on' => $data['occurred_on'],
                        'amount' => $data['amount'],
                        'direction' => 'in',
                        'mode' => $data['mode'] ?? 'bank',
                        'source' => $source,
                        'reference' => $data['reference'] ?? null,
                        'notes' => $data['notes'] ?? null,
                        'created_by' => auth()->id(),
                    ]);
                }),

            Action::make('customize')
                ->label('Customize')
                ->icon('heroicon-o-adjustments-horizontal')
                ->color('gray')
                ->fillForm(fn () => $this->getVisibleRegions())
                ->form([
                    \Filament\Forms\Components\Section::make('Choose what to see')
                        ->description('Toggle dashboard regions on or off. Saved per user.')
                        ->schema(collect(self::DASHBOARD_REGIONS)
                            ->map(fn ($label, $key) => Checkbox::make($key)
                                ->label($label)
                                ->default(true)
                            )->values()->all()),
                ])
                ->action(function (array $data): void {
                    $user = auth()->user();
                    $prefs = [];
                    foreach (array_keys(self::DASHBOARD_REGIONS) as $key) {
                        $prefs[$key] = (bool) ($data[$key] ?? false);
                    }
                    $user->forceFill(['books_dashboard_prefs' => $prefs])->save();
                }),

            Action::make('newFy')
                ->label('+ New FY')
                ->icon('heroicon-o-plus')
                ->color('gray')
                ->form([
                    TextInput::make('label')
                        ->required()
                        ->placeholder('e.g. 2026-27')
                        ->helperText('Indian financial year label (Apr–Mar).'),
                    DatePicker::make('start_date')
                        ->label('Start (Apr 1)')
                        ->required()
                        ->default(function () {
                            $year = (int) Carbon::parse($this->fy->end_date)->format('Y');

                            return $year.'-04-01';
                        }),
                    DatePicker::make('end_date')
                        ->label('End (Mar 31)')
                        ->required()
                        ->default(function () {
                            $year = (int) Carbon::parse($this->fy->end_date)->format('Y');

                            return ($year + 1).'-03-31';
                        }),
                ])
                ->action(function (array $data): void {
                    $fy = FiscalYear::create([
                        'company_id' => $this->company->id,
                        'label' => $data['label'],
                        'start_date' => $data['start_date'],
                        'end_date' => $data['end_date'],
                    ]);
                    $this->redirect(url('/admin/books/'.$this->company->slug.'/'.$fy->label));
                }),

            Action::make('closeFy')
                ->label('Close FY')
                ->icon('heroicon-o-lock-closed')
                ->color('warning')
                ->visible(fn () => ! $this->fy->is_closed)
                ->requiresConfirmation()
                ->modalDescription('Closing freezes every entry, payment, and income line in FY '.$this->fy->label.'. You can reopen it any time — the snapshot will refresh.')
                ->action(function (): void {
                    (new ClosingSnapshotWriter)->close($this->fy);
                    $this->redirect(request()->url());
                }),

            Action::make('reopenFy')
                ->label('Reopen FY')
                ->icon('heroicon-o-lock-open')
                ->color('warning')
                ->visible(fn () => $this->fy->is_closed)
                ->requiresConfirmation()
                ->modalDescription('Reopening clears the closing snapshot so prior-year carryover will recompute live until the next close.')
                ->action(function (): void {
                    (new ClosingSnapshotWriter)->reopen($this->fy);
                    $this->redirect(request()->url());
                }),

            Action::make('viewHistory')
                ->label('History')
                ->icon('heroicon-o-clock')
                ->color('gray')
                ->url(fn () => url('/admin/books/history')),
        ];
    }

    public function getKpis(): array
    {
        $agg = new FiscalYearAggregator;
        $carry = $agg->carryover($this->fy);

        return [
            'total_income' => $agg->totalIncome($this->fy),
            'cash_received' => $agg->cashInflowFromRecoveries($this->fy),
            'cash_outflow' => $agg->cashOutflow($this->fy),
            'non_cash_outflow' => $agg->nonCashOutflow($this->fy),
            'total_outflow' => $agg->totalOutflow($this->fy),
            'net_pl' => $agg->netPl($this->fy),
            'carryover' => $carry,
            'cumulative_pl' => $agg->netPl($this->fy) + $carry['value'],
            'loans_given_outstanding' => $this->loansOutstandingForSlug('loan'),
            'loans_taken_outstanding' => $this->loansOutstandingForSlug('loans_taken'),
            'loan_taken_principal' => $agg->loanTakenPrincipal($this->fy),
            'salary_paid' => $this->paidTotalForSlug('salary'),
            'balance_available' => $agg->balanceAvailable($this->fy),
        ];
    }

    /**
     * Per-tile sparkline series + period-over-period delta vs prior FY.
     * Keyed by KPI key. Each entry: ['series' => float[], 'delta_pct' => ?float, 'prior_label' => ?string].
     * delta_pct null = no prior FY or prior was zero (no meaningful comparison).
     */
    public function getKpiMeta(): array
    {
        $agg = new FiscalYearAggregator;
        $prior = $agg->priorYearKpis($this->fy);
        $current = $this->getKpis();
        $priorLabel = $prior['label'] ?? null;

        $delta = function (?float $now, ?float $then) {
            if ($then === null || abs($then) < 0.01) {
                return null;
            }

            return (($now - $then) / abs($then)) * 100.0;
        };

        $meta = [];
        $sparkableKeys = ['total_income', 'cash_outflow', 'cash_received', 'salary_paid', 'non_cash_outflow', 'total_outflow', 'net_pl'];
        foreach ($sparkableKeys as $key) {
            $meta[$key] = [
                'series' => $agg->monthlySeries($this->fy, $key),
                'delta_pct' => $prior ? $delta((float) $current[$key], (float) ($prior[$key] ?? 0)) : null,
                'prior_label' => $priorLabel,
            ];
        }

        return $meta;
    }

    private function paidTotalForSlug(string $slug): float
    {
        return (float) EntryPayment::query()
            ->where('direction', 'out')
            ->whereHas('entry', fn ($q) => $q->where('fiscal_year_id', $this->fy->id)
                ->whereHas('section', fn ($s) => $s->where('slug', $slug)))
            ->sum('amount');
    }

    private function loansOutstandingForSlug(string $slug): float
    {
        $entries = Entry::where('fiscal_year_id', $this->fy->id)
            ->whereHas('section', fn ($q) => $q->where('slug', $slug))
            ->where('loan_amount', '>', 0)
            ->get();

        return (float) $entries->sum(fn ($e) => $slug === 'loans_taken'
            ? (float) $e->loan_outstanding_taken
            : (float) $e->loan_outstanding);
    }

    public function explainKpiAction(): Action
    {
        return Action::make('explainKpi')
            ->modalHeading(fn (array $arguments) => 'How "'.($arguments['label'] ?? 'KPI').'" is computed')
            ->modalWidth('3xl')
            ->modalContent(function (array $arguments): View {
                $key = $arguments['key'] ?? '';
                $kpis = $this->getKpis();

                // KPIs backed by actual payment events get a full record list.
                if (in_array($key, ['cash_received', 'cash_outflow'], true)) {
                    $direction = $key === 'cash_received' ? 'in' : 'out';
                    $query = EntryPayment::query()
                        ->where('direction', $direction)
                        ->whereHas('entry', fn ($q) => $q->where('fiscal_year_id', $this->fy->id))
                        ->with(['entry.section', 'createdBy'])
                        ->orderBy('occurred_on');
                    if ($key === 'cash_received') {
                        $query->whereHas('entry.section', fn ($s) => $s->whereIn(
                            'slug',
                            FiscalYearAggregator::CASH_RECEIVED_SECTION_SLUGS
                        ));
                    }
                    $payments = $query->get();

                    return view('filament.pages.book.partials.kpi-payments', [
                        'payments' => $payments,
                        'total' => (float) $payments->sum('amount'),
                        'label' => $arguments['label'] ?? 'Payments',
                        'companySlug' => $this->company->slug,
                        'fyLabel' => $this->fy->label,
                    ]);
                }

                // Non-cash outflow = per-asset depreciation events.
                if ($key === 'non_cash_outflow') {
                    $assets = Asset::query()
                        ->whereHas('entry', fn ($q) => $q->where('fiscal_year_id', $this->fy->id))
                        ->with('entry.section')
                        ->get();
                    $calc = new DepreciationCalculator;

                    return view('filament.pages.book.partials.kpi-depreciation', [
                        'rows' => $assets->map(fn ($a) => [
                            'name' => $a->entry->title,
                            'section_slug' => $a->entry->section?->slug,
                            'original' => (float) $a->original_value,
                            'percent' => (float) $a->dep_percent,
                            'method' => $a->method,
                            'this_year' => $calc->yearlyDepFor($a, $this->fy),
                        ])->all(),
                        'total' => (float) $kpis['non_cash_outflow'],
                        'companySlug' => $this->company->slug,
                        'fyLabel' => $this->fy->label,
                    ]);
                }

                // Derived KPIs get a formula breakdown.
                $fmt = fn ($n) => '₹'.number_format((float) $n, 2);
                $rows = match ($key) {
                    'total_outflow' => [
                        ['Cash Outflow (direction=out payments)', $fmt($kpis['cash_outflow'])],
                        ['+ Non-Cash Outflow (depreciation)',      $fmt($kpis['non_cash_outflow'])],
                        ['= Total Outflow',                        $fmt($kpis['total_outflow']), true],
                    ],
                    'net_pl' => [
                        ['Total Income',                           $fmt($kpis['total_income'])],
                        ['− Total Outflow',                        '−'.$fmt($kpis['total_outflow'])],
                        ['= Net P/L (this FY)',                    $fmt($kpis['net_pl']), true],
                        ['Cash Received (recoveries) — info only', $fmt($kpis['cash_received'])],
                    ],
                    'carryover' => $kpis['carryover']['estimate']
                        ? [
                            ['Prior FY exists but is still OPEN — value is live and may move until that FY is closed.', ''],
                            ['Carryover (estimate)',               $fmt($kpis['carryover']['value']), true],
                        ]
                        : ($kpis['carryover']['value'] == 0 && ! $this->priorFyLabel()
                            ? [
                                ['No prior fiscal year exists for this company.', ''],
                                ['Create a prior-year FY from + New FY in the header if you want a non-zero carryover.', ''],
                                ['Carryover',                      $fmt(0), true],
                            ]
                            : [
                                ['Net P/L of the most recent closed prior FY.', ''],
                                ['Carryover',                      $fmt($kpis['carryover']['value']), true],
                            ]),
                    'cumulative_pl' => [
                        ['Net P/L (this FY)',                      $fmt($kpis['net_pl'])],
                        ['+ Carryover from prior FY',              $fmt($kpis['carryover']['value'])],
                        ['= Cumulative P/L',                       $fmt($kpis['cumulative_pl']), true],
                    ],
                    default => [['Unknown KPI: '.$key, '']],
                };

                return view('filament.pages.book.partials.kpi-breakdown', ['rows' => $rows]);
            })
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Close');
    }

    public function getSectionRollups(): array
    {
        return $this->company->sections()
            ->orderBy('sort_order')->get()
            ->map(function ($s) {
                $entries = Entry::where('section_id', $s->id)
                    ->where('fiscal_year_id', $this->fy->id)->get();

                return [
                    'section' => $s,
                    'count' => $entries->count(),
                    'salary_total' => $entries->sum(fn ($e) => (float) $e->annualized_salary_amount),
                    'loan_total' => $entries->sum(fn ($e) => (float) $e->loan_amount),
                    'paid_total' => $entries->sum(fn ($e) => (float) $e->paid),
                    'received_back_total' => $entries->sum(fn ($e) => (float) $e->received_back),
                    'balance_total' => $entries->sum(fn ($e) => (float) $e->balance),
                ];
            })->all();
    }

    public function getAssetRegister(): array
    {
        $calc = new DepreciationCalculator;

        return Asset::whereHas('entry',
            fn ($q) => $q->where('fiscal_year_id', $this->fy->id))
            ->with('entry.section')->get()
            ->map(fn ($a) => [
                'id' => $a->entry->id,
                'name' => $a->entry->title,
                'section_slug' => $a->entry->section?->slug ?? 'assets',
                'original' => (float) $a->original_value,
                'this_year' => $calc->yearlyDepFor($a, $this->fy),
                'accumulated' => $calc->accumulatedDepThrough($a, $this->fy),
                'book_value' => $calc->bookValueAtEndOf($a, $this->fy),
            ])->all();
    }

    public function getLoansOutstanding(): array
    {
        return Entry::where('fiscal_year_id', $this->fy->id)
            ->where('loan_amount', '>', 0)->with('section')->get()
            ->filter(function ($e) {
                $isTaken = $e->section?->slug === 'loans_taken';
                $outstanding = $isTaken ? (float) $e->loan_outstanding_taken : (float) $e->loan_outstanding;

                return $outstanding > 0;
            })
            ->map(function ($e) {
                $isTaken = $e->section?->slug === 'loans_taken';

                return [
                    'id' => $e->id,
                    'title' => $e->title,
                    'kind' => $isTaken ? 'taken' : 'given',
                    'interest_rate' => $e->interest_rate,
                    'loan' => (float) $e->loan_amount,
                    'received_back' => (float) $e->received_back,
                    'repaid' => (float) $e->repaid,
                    'outstanding' => $isTaken ? (float) $e->loan_outstanding_taken : (float) $e->loan_outstanding,
                    'section_slug' => $e->section?->slug,
                ];
            })->values()->all();
    }

    public function defaultGenericSection(): ?Section
    {
        return $this->company->sections()
            ->where('kind', 'generic')->orderBy('sort_order')->first();
    }

    public function assetSection(): ?Section
    {
        return $this->company->sections()->where('kind', 'asset')->first();
    }

    public function priorFyLabel(): ?string
    {
        $prior = FiscalYear::where('company_id', $this->company->id)
            ->where('end_date', '<', $this->fy->start_date)
            ->orderByDesc('end_date')->first();

        return $prior?->label;
    }

    public function entrySectionSlug(int $entryId): ?string
    {
        $entry = Entry::with('section')->find($entryId);

        return $entry?->section?->slug;
    }
}
