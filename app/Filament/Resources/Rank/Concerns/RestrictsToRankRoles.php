<?php

namespace App\Filament\Resources\Rank\Concerns;

use App\Rank\RankAccess;

trait RestrictsToRankRoles
{
    public static function canAccess(): bool
    {
        $user = auth()->user();

        return RankAccess::isLegacyAdmin($user)
            || RankAccess::analysableDatasets($user) !== [];
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }
}
