<?php

namespace App\Filament\Pages;

use App\Dashboard\Card;
use App\Dashboard\Resolver\UserPrefsResolver;
use Filament\Pages\Page;

class DashboardPage extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-home';

    protected static ?string $navigationLabel = 'Dashboard';

    protected static ?int $navigationSort = 2;

    protected static ?string $slug = '/';

    protected static ?string $title = 'Dashboard';

    protected static string $view = 'filament.pages.dashboard';

    public static function canAccess(): bool
    {
        return auth()->check();
    }

    /** @return Card[] */
    public function cards(): array
    {
        return app(UserPrefsResolver::class)->resolve(auth()->user(), 'dashboard');
    }

    public function surface(): string
    {
        return 'dashboard';
    }
}
