<?php

namespace App\Filament\Resources\Rank\Concerns;

trait RestrictsToRankRoles
{
    public static function canAccess(): bool
    {
        $user = auth()->user();

        return \App\Rank\RankAccess::isLegacyAdmin($user)
            || \App\Rank\RankAccess::analysableDatasets($user) !== [];
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }
}
