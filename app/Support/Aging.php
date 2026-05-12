<?php

namespace App\Support;

use Carbon\Carbon;

/**
 * Aging indicator helper — converts a Carbon timestamp into a small coloured
 * dot HTML snippet used as a "freshness at a glance" cue on student cards,
 * list rows and dashboard widgets.
 *
 *  green  ≤ 3 days
 *  amber  4–14 days
 *  red    15+ days
 */
class Aging
{
    public static function dotHtml(?Carbon $ts): string
    {
        $age = $ts ? (int) $ts->diffInDays(now()) : 0;
        $color = $age <= 3
            ? 'var(--success,#10B981)'
            : ($age <= 14 ? 'var(--warning,#F59E0B)' : 'var(--danger,#EF4444)');
        $label = $age === 0
            ? 'updated today'
            : ($age === 1 ? '1 day since update' : "{$age} days since update");

        return '<span class="davya-age-dot" style="background:'.$color.';" title="'.htmlspecialchars($label, ENT_QUOTES).'" aria-label="'.htmlspecialchars($label, ENT_QUOTES).'"></span>';
    }
}
