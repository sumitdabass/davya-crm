<?php

namespace App\Filament\Pages;

use App\Finance\FinanceRegistry;
use Filament\Pages\Page;

class FinanceLanding extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-currency-rupee';

    protected static ?string $navigationLabel = 'All finance';

    protected static ?string $navigationGroup = 'Finance';

    protected static ?int $navigationSort = 0;

    protected static ?string $slug = 'finance';

    protected static ?string $title = 'Finance';

    protected static string $view = 'filament.pages.finance-landing';

    public static function canAccess(): bool
    {
        return FinanceRegistry::anyAccessibleFor(auth()->user());
    }

    public function getCards(): array
    {
        return FinanceRegistry::accessibleFor(auth()->user());
    }
}
