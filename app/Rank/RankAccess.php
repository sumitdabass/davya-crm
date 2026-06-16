<?php

namespace App\Rank;

use App\Models\User;

class RankAccess
{
    private const LEGACY_ROLES = ['admin', 'rank-admin', 'super_admin'];

    public static function isLegacyAdmin(?User $user): bool
    {
        return (bool) $user?->hasAnyRole(self::LEGACY_ROLES);
    }

    /** @return array<int,string> dataset tokens the user can use the predictor for */
    public static function predictableDatasets(?User $user): array
    {
        if ($user === null) {
            return [];
        }
        if (self::isLegacyAdmin($user)) {
            return RankDataset::tokens();
        }

        return array_values(array_filter(RankDataset::tokens(), fn (string $t) => $user->canRankPredict($t)));
    }

    /** @return array<int,string> dataset tokens the user can analyse (CRUD/trends) */
    public static function analysableDatasets(?User $user): array
    {
        if ($user === null) {
            return [];
        }
        if (self::isLegacyAdmin($user)) {
            return RankDataset::tokens();
        }

        return array_values(array_filter(RankDataset::tokens(), fn (string $t) => $user->canRankAnalyse($t)));
    }

    /** @return array<int,string> university codes across the user's analysable datasets */
    public static function analysableUniversityCodes(?User $user): array
    {
        $codes = [];
        foreach (self::analysableDatasets($user) as $token) {
            $codes = array_merge($codes, RankDataset::universityCodes($token));
        }

        return array_values(array_unique($codes));
    }

    public static function canSeeAnyRankTool(?User $user): bool
    {
        return self::predictableDatasets($user) !== [] || self::analysableDatasets($user) !== [];
    }
}
