<?php

namespace App\Filament\Pages;

use App\Dashboard\Card;
use App\Dashboard\Resolver\UserPrefsResolver;
use Filament\Pages\Page;
use Livewire\Attributes\On;

class DashboardPage extends Page
{
    #[On('dashboard-prefs-saved')]
    public function refreshAfterPrefsSaved(): void
    {
        // Empty body — listener exists so Livewire re-renders the page,
        // forcing $this->cards() to recompute and the empty-state branch
        // in dashboard.blade.php to evaluate against the freshly saved prefs.
    }

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
