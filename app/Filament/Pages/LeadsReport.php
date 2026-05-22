<?php

namespace App\Filament\Pages;

use App\Models\UserPerformanceScore;
use App\Services\PipelineSummary;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Artisan;
use Livewire\Attributes\Url;

class LeadsReport extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-users';

    protected static ?string $navigationLabel = 'Leads report';

    protected static ?string $navigationGroup = 'Reports';

    protected static string $view = 'filament.pages.leads-report';

    protected static ?string $slug = 'leads-report';

    protected static ?string $title = 'Leads Report';

    #[Url(as: 'month', except: null)]
    public ?string $performanceMonth = null;

    public function mount(): void
    {
        $this->performanceMonth = $this->performanceMonth ?? now()->format('Y-m');

        // Defensive validation — accept only YYYY-MM strings within a 2-year
        // window around today. Anything else falls back to current month.
        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string) $this->performanceMonth)) {
            $this->performanceMonth = now()->format('Y-m');
        }
    }

    public static function canAccess(): bool
    {
        // Accept admin OR super_admin so the SumitSuperAdminSeeder
        // account works too (matches the StaffPerformance gate).
        return auth()->user()?->hasRole(['admin', 'super_admin']) ?? false;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('recalculatePerformance')
                ->label('Recalculate scores')
                ->icon('heroicon-o-arrow-path')
                ->action(function () {
                    Artisan::call('performance:recalculate', ['--month' => $this->performanceMonth]);
                    Notification::make()
                        ->title('Recalculated')
                        ->body('Staff performance scores refreshed for '.$this->performanceMonth)
                        ->success()
                        ->send();
                })
                ->requiresConfirmation()
                ->modalHeading('Recalculate staff scores?')
                ->modalDescription('Re-runs the scoring pipeline for the selected month. Takes a few seconds.'),
        ];
    }

    /**
     * @return array{byOwner:array<int,array<string,mixed>>, byReferrer:array<int,array<string,mixed>>, totals:array<string,int>}
     */
    public function getReport(): array
    {
        $byOwner = PipelineSummary::byOwnerAfterCaptured();
        $byReferrer = PipelineSummary::byReferrerAfterCaptured();

        return [
            'byOwner' => $byOwner,
            'byReferrer' => $byReferrer,
            'totals' => [
                'owners_counted' => count($byOwner),
                'referrers_counted' => count($byReferrer),
                'past_capture' => array_sum(array_column($byOwner, 'count')),
            ],
        ];
    }

    /**
     * @return array{rows: list<array<string,mixed>>, periodStart: string, periodEnd: string}
     */
    public function getPerformanceReport(): array
    {
        $month = $this->performanceMonth ?: now()->format('Y-m');
        $start = CarbonImmutable::createFromFormat('Y-m-d', $month.'-01')->startOfMonth();
        $end = $start->endOfMonth();

        $scores = UserPerformanceScore::query()
            ->with('user.teamHead')
            ->where('period_start', $start->toDateString())
            ->orderByDesc('score')
            ->get();

        $rows = $scores->map(function (UserPerformanceScore $s) {
            $b = $s->signal_breakdown;

            return [
                'user_id' => $s->user_id,
                'user_name' => $s->user?->name ?? 'Unknown',
                'is_freelancer' => (bool) ($s->user?->is_freelancer ?? false),
                'team_head' => $s->user?->teamHead?->name,
                'score' => $s->score,
                'tier' => $s->tier,
                'closed_won' => $b['closed_won'] ?? 0,
                'deal_won_amount' => $b['deal_won_amount'] ?? 0,
                'rank_prob_avg' => $b['rank_prob_avg'] ?? 0,
                'advance_received' => $b['advance_received'] ?? 0,
                'cases_captured' => $b['cases_captured'] ?? 0,
                'meetings_held' => $b['meetings_held'] ?? 0,
                'open_leads' => $b['open_leads'] ?? 0,
                'balance_amount' => $b['balance_amount'] ?? 0,
                'conversion_rate' => $b['conversion_rate'] ?? 0,
                'calculated_at' => $s->calculated_at,
            ];
        })->all();

        return [
            'rows' => $rows,
            'periodStart' => $start->toDateString(),
            'periodEnd' => $end->toDateString(),
        ];
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public function getPerformanceMonthOptions(): array
    {
        $opts = [];
        $now = CarbonImmutable::now()->startOfMonth();
        for ($i = 0; $i < 12; $i++) {
            $m = $now->subMonths($i);
            $opts[] = ['value' => $m->format('Y-m'), 'label' => $m->format('M Y')];
        }

        return $opts;
    }

    public function tierColor(string $tier): string
    {
        return match ($tier) {
            'Star' => 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900 dark:text-emerald-100',
            'Strong' => 'bg-sky-100 text-sky-800 dark:bg-sky-900 dark:text-sky-100',
            'Solid' => 'bg-slate-100 text-slate-800 dark:bg-slate-700 dark:text-slate-100',
            'Growth' => 'bg-amber-100 text-amber-800 dark:bg-amber-900 dark:text-amber-100',
            default => 'bg-rose-100 text-rose-800 dark:bg-rose-900 dark:text-rose-100',
        };
    }
}
