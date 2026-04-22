<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;

class TodayPage extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-sun';

    protected static ?string $navigationLabel = 'Today';

    protected static ?int $navigationSort = 1;

    protected static ?string $slug = 'today';

    protected static ?string $title = 'Today';

    protected static string $view = 'filament.pages.today-page';

    public static function canAccess(): bool
    {
        return auth()->check();
    }

    protected function getHeaderWidgets(): array
    {
        return [
            \App\Filament\Widgets\TodayMeetingsWidget::class,
            \App\Filament\Widgets\TodayPaymentsWidget::class,
        ];
    }

    public function getHeaderWidgetsColumns(): int | array
    {
        return 1;
    }
}
