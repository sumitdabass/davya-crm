<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;

class InstallApp extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-arrow-down-tray';
    protected static ?string $slug = 'install-app';
    protected static ?string $title = 'Install Davya CRM';
    protected static string $view = 'filament.pages.install-app';

    public static function shouldRegisterNavigation(): bool
    {
        // Accessed only via the user menu; don't clutter the sidebar.
        return false;
    }
}
