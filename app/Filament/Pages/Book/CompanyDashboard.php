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
                    'salary_total'  => $entries->sum(fn ($e) => (float) $e->salary_amount),
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
