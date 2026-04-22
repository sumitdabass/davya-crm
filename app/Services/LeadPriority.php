<?php

namespace App\Services;

use App\Models\User;

/**
 * Tier rules for duplicate-lead ownership resolution.
 *
 *   Sonam  > Nikhil > Sumit > (anyone else)
 *
 * When a new lead's phone matches an existing student, the higher tier wins.
 * When both are head-tier but different heads (Sonam vs Nikhil), the conflict
 * goes to the admin as a manual-review flag (not auto-merged).
 */
class LeadPriority
{
    public const TIER_SONAM  = 3;
    public const TIER_NIKHIL = 2;
    public const TIER_SUMIT  = 1;
    public const TIER_OTHER  = 0;

    public static function tier(?User $user): int
    {
        if ($user === null) {
            return self::TIER_OTHER;
        }
        $n = strtolower(trim((string) $user->name));
        if ($n === '') {
            return self::TIER_OTHER;
        }
        if (str_contains($n, 'sonam'))  return self::TIER_SONAM;
        if (str_contains($n, 'nikhil')) return self::TIER_NIKHIL;
        if (str_contains($n, 'sumit'))  return self::TIER_SUMIT;
        return self::TIER_OTHER;
    }

    public static function tierByName(?string $name): int
    {
        if ($name === null || trim($name) === '') {
            return self::TIER_OTHER;
        }
        $u = new User(['name' => $name]);
        return self::tier($u);
    }

    public static function isHeadTier(int $tier): bool
    {
        return $tier === self::TIER_SONAM || $tier === self::TIER_NIKHIL;
    }
}
