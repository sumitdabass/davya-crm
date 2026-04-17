<?php

namespace App\Filament\Widgets;

use App\Services\PipelineSummary;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class PipelineSummaryWidget extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected function getStats(): array
    {
        $summary = PipelineSummary::compute();

        $stats = [];
        foreach ($summary as $stage => $row) {
            if ($row['count'] === 0 && $row['total'] === 0.0) {
                continue;
            }
            $stats[] = Stat::make($stage, $row['count'])
                ->description('₹'.number_format($row['total'], 0, '.', ','))
                ->descriptionIcon('heroicon-m-banknotes')
                ->color($this->colorForStage($stage));
        }

        if ($stats === []) {
            $stats[] = Stat::make('Pipeline', '0')->description('No students yet');
        }

        return $stats;
    }

    private function colorForStage(string $stage): string
    {
        return match ($stage) {
            'Closed' => 'gray',
            'Admission Confirmed', 'Full Payment Received' => 'success',
            'Seat Allotted', 'Counselling In Progress' => 'info',
            'Onboarded', 'Meeting Done' => 'warning',
            default => 'primary',
        };
    }
}
