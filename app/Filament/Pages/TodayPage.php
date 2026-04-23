<?php

namespace App\Filament\Pages;

use App\Dashboard\Card;
use App\Dashboard\Resolver\UserPrefsResolver;
use Filament\Pages\Page;
use Livewire\Attributes\On;

class TodayPage extends Page
{
    #[On('dashboard-prefs-saved')]
    public function refreshAfterPrefsSaved(): void
    {
        // See DashboardPage::refreshAfterPrefsSaved — same purpose.
    }

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

    /** @return Card[] */
    public function cards(): array
    {
        return app(UserPrefsResolver::class)->resolve(auth()->user(), 'today');
    }

    public function surface(): string
    {
        return 'today';
    }
}
