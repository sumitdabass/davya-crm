<?php

namespace App\Filament\Pages;

use App\Reports\ReportRegistry;
use Filament\Pages\Page;

class ReportsLanding extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-chart-bar-square';

    protected static ?string $navigationLabel = 'All reports';

    protected static ?string $navigationGroup = 'Reports';

    protected static ?int $navigationSort = 0;

    protected static ?string $slug = 'reports';

    protected static ?string $title = 'Reports';

    protected static string $view = 'filament.pages.reports-landing';

    public static function canAccess(): bool
    {
        return ReportRegistry::anyAccessibleFor(auth()->user());
    }

    public function getCards(): array
    {
        return ReportRegistry::accessibleFor(auth()->user());
    }
}
