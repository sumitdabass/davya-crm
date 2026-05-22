<?php

namespace App\Filters;

/**
 * Canonical mapping for the three semantic "watchlist" filters that surface
 * in multiple places across the panel. Each surface uses a slightly different
 * shape on purpose (URL terseness on kanban, Filament's filter contract on
 * the resource list, card IDs on the dashboard) — this class is the single
 * source of truth so a rename in one place doesn't silently drift from the
 * others.
 *
 * Use the helpers when constructing URLs so the dashboard cards, kanban
 * filters, and students-list deep-links stay in lockstep.
 */
class FilterKeys
{
    /** Identifier shared by all three surfaces — referenced in tests. */
    public const STUCK            = 'stuck';
    public const RE_ENTRY         = 're_entry';
    public const SEAT_FEE_PENDING = 'seat_fee_pending';

    /** Dashboard CardRegistry IDs. */
    public const CARD_IDS = [
        self::STUCK            => 'stuck_leads',
        self::RE_ENTRY         => 're_entry_candidates',
        self::SEAT_FEE_PENDING => 'seat_fee_pending',
    ];

    /** Kanban `#[Url]` querystring keys (short, no nesting). */
    public const KANBAN_URL_KEYS = [
        self::STUCK            => 'stuck',
        self::RE_ENTRY         => 're_entry',
        self::SEAT_FEE_PENDING => 'seat_fee',
    ];

    /** StudentResource Filament filter names. */
    public const STUDENTS_FILTER_NAMES = [
        self::STUCK            => 'stuck',
        self::RE_ENTRY         => 're_entry',
        self::SEAT_FEE_PENDING => 'seat_fee_pending',
    ];

    public static function studentsListUrl(string $semantic): string
    {
        $name = self::STUDENTS_FILTER_NAMES[$semantic]
            ?? throw new \InvalidArgumentException("Unknown filter semantic: {$semantic}");

        return '/admin/students?'.http_build_query([
            'tableFilters' => [$name => ['isActive' => 1]],
        ]);
    }

    public static function kanbanUrl(string $semantic): string
    {
        $key = self::KANBAN_URL_KEYS[$semantic]
            ?? throw new \InvalidArgumentException("Unknown filter semantic: {$semantic}");

        return '/admin/kanban?'.$key.'=1';
    }
}
