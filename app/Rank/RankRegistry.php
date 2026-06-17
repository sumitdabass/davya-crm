<?php

namespace App\Rank;

use App\Models\User;

class RankRegistry
{
    /** Manage (source-data) cards, shown to analyse-capable users. */
    private const MANAGE = [
        ['key' => 'manage-universities', 'title' => 'Universities', 'desc' => 'University records (name, code, state, website).', 'icon' => 'heroicon-o-building-library', 'url' => '/admin/rank/universities'],
        ['key' => 'manage-institutes', 'title' => 'Institutes', 'desc' => 'Colleges + institutes per university.', 'icon' => 'heroicon-o-building-office', 'url' => '/admin/rank/institutes'],
        ['key' => 'manage-courses', 'title' => 'Courses', 'desc' => 'Courses offered per university.', 'icon' => 'heroicon-o-academic-cap', 'url' => '/admin/rank/courses'],
        ['key' => 'manage-branches', 'title' => 'Branches', 'desc' => 'Specialisations inside each course.', 'icon' => 'heroicon-o-rectangle-stack', 'url' => '/admin/rank/branches'],
        ['key' => 'manage-cutoffs', 'title' => 'Cutoffs', 'desc' => 'Historical cutoffs per year / round / region. Bulk-paste import.', 'icon' => 'heroicon-o-chart-bar', 'url' => '/admin/rank/cutoffs'],
        ['key' => 'manage-seats', 'title' => 'Seats', 'desc' => 'Seat counts per year / branch / institute.', 'icon' => 'heroicon-o-squares-2x2', 'url' => '/admin/rank/seats'],
    ];

    /** @return array<int,array<string,string>> role-filtered cards for the landing */
    public static function cardsFor(?User $user): array
    {
        if (! RankAccess::canSeeAnyRankTool($user)) {
            return [];
        }

        $cards = [];

        // Predict cards — one per predictable dataset, links to the new predictor page.
        foreach (RankAccess::predictableDatasets($user) as $token) {
            $label = RankDataset::label($token);
            $cards[] = [
                'key' => "predict-{$token}",
                'group' => 'predict',
                'title' => "{$label} — Predict",
                'desc' => "Predict eligible colleges + branches for a {$label} rank, with category, sub-category, gender, and chance scale.",
                'icon' => $token === 'ipu' ? 'heroicon-o-magnifying-glass' : 'heroicon-o-academic-cap',
                'url' => "/admin/rank/{$token}/predict",
            ];
        }

        // Analytics — year-on-year cutoff trends. Separate from the predictor.
        if (in_array('dtu', RankAccess::predictableDatasets($user), true)) {
            $cards[] = [
                'key' => 'dtu-cutoff-trends',
                'group' => 'analytics',
                'title' => 'DTU — Cutoff Trends',
                'desc' => 'Year-on-year DTU/NSUT/IGDTUW cutoff movement, projecting the newer year\'s final round from the latest published round.',
                'icon' => 'heroicon-o-arrow-trending-up',
                'url' => '/admin/rank/dtu/compare',
            ];
        }

        // Manage cards — shown if the user can analyse ANY dataset (resources self-scope).
        if (RankAccess::analysableDatasets($user) !== []) {
            foreach (self::MANAGE as $card) {
                $cards[] = $card + ['group' => 'manage'];
            }
        }

        // Legacy IPU Rank Lookup — kept reachable until category-wise IPU data lands.
        if (in_array('ipu', RankAccess::predictableDatasets($user), true)
            || in_array('ipu', RankAccess::analysableDatasets($user), true)) {
            $cards[] = [
                'key' => 'legacy-lookup',
                'group' => 'legacy',
                'title' => 'IPU Rank Lookup (legacy)',
                'desc' => 'Older IPU branch-family lookup. Use “IPU — Predict” for the new category-aware tool.',
                'icon' => 'heroicon-o-clock',
                'url' => '/admin/rank-lookup',
            ];
        }

        return $cards;
    }

    /** Back-compat: landing access gate. */
    public static function canAccess(?User $user): bool
    {
        return RankAccess::canSeeAnyRankTool($user);
    }
}
