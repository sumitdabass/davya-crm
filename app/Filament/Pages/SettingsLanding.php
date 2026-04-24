<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;

class SettingsLanding extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-cog-6-tooth';
    protected static ?string $navigationLabel = 'Settings';
    protected static ?string $title = 'Settings';
    protected static ?string $slug = 'settings';
    protected static string $view = 'filament.pages.settings-landing';
    protected static ?string $navigationGroup = 'Setup';
    protected static ?int $navigationSort = 100;

    public static function canAccess(): bool
    {
        return auth()->user()?->hasRole('admin') ?? false;
    }

    public static function shouldRegisterNavigation(): bool
    {
        return config('davyas.visual_v2') === true
            && auth()->user()?->hasRole('admin') === true;
    }

    public function getTiles(): array
    {
        return [
            ['label' => 'Fields',           'icon' => 'heroicon-o-rectangle-stack',  'desc' => 'Student schema: sections, fields, required flags.', 'url' => '/admin/student-fields'],
            ['label' => 'Stages',           'icon' => 'heroicon-o-arrows-right-left','desc' => 'Pipeline stages, transition rules, Won/Lost.',       'url' => '/admin/pipeline-config'],
            ['label' => 'Duplicate review', 'icon' => 'heroicon-o-document-duplicate','desc' => 'Resolve flagged duplicate leads.',                   'url' => '/admin/duplicate-flags'],
            ['label' => 'Users & roles',    'icon' => 'heroicon-o-users',            'desc' => 'Counsellors, heads, permissions.',                   'url' => '/admin/users'],
            ['label' => 'Lead import',      'icon' => 'heroicon-o-arrow-up-tray',    'desc' => 'CSV import with dedup + re-parent.',                 'url' => '/admin/lead-import'],
            ['label' => 'Activity audit',   'icon' => 'heroicon-o-clipboard-document-list','desc' => 'Every password reveal, field change, stage move.', 'url' => '/admin/activity-audit'],
        ];
    }
}
