<?php

namespace App\Filament\Pages;

use App\Models\UserPerformanceScore;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Artisan;

class StaffPerformance extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-trophy';

    protected static ?string $navigationLabel = 'Staff performance';

    protected static ?string $navigationGroup = 'Reports';

    protected static string $view = 'filament.pages.staff-performance';

    protected static ?string $slug = 'staff-performance';

    protected static ?string $title = 'Staff Performance';

    public ?string $month = null;

    public function mount(): void
    {
        $this->month = $this->month ?? now()->format('Y-m');
    }

    public static function canAccess(): bool
    {
        // Accept admin OR super_admin. The SumitSuperAdminSeeder + the
        // 2026_05_02_000300_create_super_admin_role migration grant
        // super_admin to the canonical Sumit account; without including
        // it here, that account would silently 404 on this page.
        return auth()->user()?->hasRole(['admin', 'super_admin']) ?? false;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('recalculate')
                ->label('Recalculate now')
                ->icon('heroicon-o-arrow-path')
                ->action(function () {
                    Artisan::call('performance:recalculate', ['--month' => $this->month]);
                    Notification::make()
                        ->title('Recalculated')
                        ->body('Scores refreshed for '.$this->month)
                        ->success()
                        ->send();
                })
                ->requiresConfirmation()
                ->modalHeading('Recalculate scores?')
                ->modalDescription('Re-runs the scoring pipeline for the selected month. Takes a few seconds.'),
        ];
    }

    /**
     * @return array{rows: list<array<string,mixed>>, periodStart: string, periodEnd: string}
     */
    public function getReport(): array
    {
        $month = $this->month ?: now()->format('Y-m');
        $start = CarbonImmutable::createFromFormat('Y-m-d', $month.'-01')->startOfMonth();
        $end = $start->endOfMonth();

        $scores = UserPerformanceScore::query()
            ->with('user')
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
    public function getMonthOptions(): array
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
