<?php

namespace App\Filament\Resources\Rank\Concerns;

use App\Rank\RankAccess;
use Illuminate\Database\Eloquent\Builder;

/**
 * Gates a Rank resource to analyse-capable users and query-scopes its rows to
 * the user's analysable datasets. Legacy admins (admin/rank-admin/super_admin)
 * get full, unscoped access — identical to the old RestrictsToRankRoles behavior.
 *
 * Each consuming resource MUST implement:
 *   protected static function scopeToRankUniversityCodes(Builder $query, array $codes): Builder
 */
trait ScopesToRankDataset
{
    public static function canAccess(): bool
    {
        $user = auth()->user();

        return RankAccess::isLegacyAdmin($user) || RankAccess::analysableDatasets($user) !== [];
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $user = auth()->user();

        if (RankAccess::isLegacyAdmin($user)) {
            return $query;
        }

        return static::scopeToRankUniversityCodes($query, RankAccess::analysableUniversityCodes($user));
    }
}
