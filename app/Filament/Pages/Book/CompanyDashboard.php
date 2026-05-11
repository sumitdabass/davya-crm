<?php

namespace App\Filament\Pages\Book;

use App\Books\Services\DepreciationCalculator;
use App\Books\Services\FiscalYearAggregator;
use App\Models\Book\Asset;
use App\Models\Book\Company;
use App\Models\Book\Entry;
use App\Models\Book\FiscalYear;
use App\Models\Book\Section;
use Filament\Pages\Page;

class CompanyDashboard extends Page
{
    protected static ?string $slug = 'books/{company}/{fy}';

    protected static bool $shouldRegisterNavigation = false;

    protected static string $view = 'filament.pages.book.company-dashboard';

    public const DASHBOARD_REGIONS = [
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
            \Filament\Actions\Action::make('customize')
                ->label('Customize')
                ->icon('heroicon-o-adjustments-horizontal')
                ->color('gray')
                ->fillForm(fn () => $this->getVisibleRegions())
                ->form([
                    \Filament\Forms\Components\Section::make('Choose what to see')
                        ->description('Toggle dashboard regions on or off. Saved per user.')
                        ->schema(collect(self::DASHBOARD_REGIONS)
                            ->map(fn ($label, $key) => \Filament\Forms\Components\Checkbox::make($key)
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

            \Filament\Actions\Action::make('newFy')
                ->label('+ New FY')
                ->icon('heroicon-o-plus')
                ->color('gray')
                ->form([
                    \Filament\Forms\Components\TextInput::make('label')
                        ->required()
                        ->placeholder('e.g. 2026-27')
                        ->helperText('Indian financial year label (Apr–Mar).'),
                    \Filament\Forms\Components\DatePicker::make('start_date')
                        ->label('Start (Apr 1)')
                        ->required()
                        ->default(function () {
                            $year = (int) \Carbon\Carbon::parse($this->fy->end_date)->format('Y');
                            return $year . '-04-01';
                        }),
                    \Filament\Forms\Components\DatePicker::make('end_date')
                        ->label('End (Mar 31)')
                        ->required()
                        ->default(function () {
                            $year = (int) \Carbon\Carbon::parse($this->fy->end_date)->format('Y');
                            return ($year + 1) . '-03-31';
                        }),
                ])
                ->action(function (array $data): void {
                    $fy = \App\Models\Book\FiscalYear::create([
                        'company_id' => $this->company->id,
                        'label' => $data['label'],
                        'start_date' => $data['start_date'],
                        'end_date' => $data['end_date'],
                    ]);
                    $this->redirect(url('/admin/books/'.$this->company->slug.'/'.$fy->label));
                }),

            \Filament\Actions\Action::make('closeFy')
                ->label('Close FY')
                ->icon('heroicon-o-lock-closed')
                ->color('warning')
                ->visible(fn () => ! $this->fy->is_closed)
                ->requiresConfirmation()
                ->modalDescription('Closing freezes every entry, payment, and income line in FY '.$this->fy->label.'. You can reopen it any time — the snapshot will refresh.')
                ->action(function (): void {
                    (new \App\Books\Services\ClosingSnapshotWriter())->close($this->fy);
                    $this->redirect(request()->url());
                }),

            \Filament\Actions\Action::make('reopenFy')
                ->label('Reopen FY')
                ->icon('heroicon-o-lock-open')
                ->color('warning')
                ->visible(fn () => $this->fy->is_closed)
                ->requiresConfirmation()
                ->modalDescription('Reopening clears the closing snapshot so prior-year carryover will recompute live until the next close.')
                ->action(function (): void {
                    (new \App\Books\Services\ClosingSnapshotWriter())->reopen($this->fy);
                    $this->redirect(request()->url());
                }),

            \Filament\Actions\Action::make('viewHistory')
                ->label('History')
                ->icon('heroicon-o-clock')
                ->color('gray')
                ->url(fn () => url('/admin/books/history')),
        ];
    }

    public function getKpis(): array
    {
        $agg = new FiscalYearAggregator();
        $carry = $agg->carryover($this->fy);

        return [
            'total_income'     => $agg->totalIncome($this->fy),
            'cash_outflow'     => $agg->cashOutflow($this->fy),
            'non_cash_outflow' => $agg->nonCashOutflow($this->fy),
            'total_outflow'    => $agg->totalOutflow($this->fy),
            'net_pl'           => $agg->netPl($this->fy),
            'carryover'        => $carry,
            'cumulative_pl'    => $agg->netPl($this->fy) + $carry['value'],
        ];
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
                    'salary_total'  => $entries->sum(fn ($e) => (float) $e->annualized_salary_amount),
                    'loan_total'    => $entries->sum(fn ($e) => (float) $e->loan_amount),
                    'paid_total'    => $entries->sum(fn ($e) => (float) $e->paid),
                    'balance_total' => $entries->sum(fn ($e) => (float) $e->balance),
                ];
            })->all();
    }

    public function getAssetRegister(): array
    {
        $calc = new DepreciationCalculator();

        return Asset::whereHas('entry',
                fn ($q) => $q->where('fiscal_year_id', $this->fy->id))
            ->with('entry.section')->get()
            ->map(fn ($a) => [
                'id'           => $a->entry->id,
                'name'         => $a->entry->title,
                'section_slug' => $a->entry->section?->slug ?? 'assets',
                'original'     => (float) $a->original_value,
                'this_year'    => $calc->yearlyDepFor($a, $this->fy),
                'accumulated' => $calc->accumulatedDepThrough($a, $this->fy),
                'book_value'  => $calc->bookValueAtEndOf($a, $this->fy),
            ])->all();
    }

    public function getLoansOutstanding(): array
    {
        return Entry::where('fiscal_year_id', $this->fy->id)
            ->where('loan_amount', '>', 0)->with('section')->get()
            ->filter(fn ($e) => (float) $e->loan_outstanding > 0)
            ->map(fn ($e) => [
                'id'            => $e->id,
                'title'         => $e->title,
                'loan'          => (float) $e->loan_amount,
                'received_back' => (float) $e->received_back,
                'outstanding'   => (float) $e->loan_outstanding,
                'section_slug'  => $e->section?->slug,
            ])->values()->all();
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
