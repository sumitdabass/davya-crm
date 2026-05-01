<?php

namespace App\Filament\Resources\Rank\Concerns;

trait RestrictsToRankRoles
{
    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user && $user->hasAnyRole(['admin', 'rank-admin']);
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }
}
