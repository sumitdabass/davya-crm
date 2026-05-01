<?php

namespace App\Services\Rank;

use App\Models\Rank\Branch;

class BranchFamilies
{
    /**
     * Family code → display label. Order is the order shown in the picker.
     */
    private const FAMILIES = [
        'cs_it' => 'Computer / IT',
        'electronics' => 'Electronics',
        'mechanical' => 'Mechanical',
        'civil_arch' => 'Civil / Architecture',
        'chem_energy' => 'Chemical / Energy',
    ];

    /**
     * Family code → list of lowercase substrings. A branch belongs to a family if
     * its lowercase name contains ANY of the family's substrings.
     */
    private const SUBSTRINGS = [
        'cs_it' => [
            'computer science', 'cse', 'cs/', 'cs (', 'cs-',
            'information technology', '(it)', ' it ', 'it (dual',
            'ai &', 'ai&', 'artificial intelligence',
            'data science', 'computer applications', 'cyber security',
        ],
        'electronics' => [
            'electronics & communication', 'ece', 'electrical', 'vlsi',
            'instrumentation', 'industrial internet of things', 'advance comm',
        ],
        'mechanical' => [
            'mechanical', 'mechatronics', 'automation & robotics', 'robotics & artificial',
        ],
        'civil_arch' => [
            'civil', 'architecture', '3d modelling', '3d modeling',
        ],
        'chem_energy' => [
            'chemical', 'energy', 'nanoscience',
        ],
    ];

    /** @return array<string, string> Family code → label, in display order. */
    public static function all(): array
    {
        return self::FAMILIES;
    }

    /**
     * Returns the first family whose substrings match the (lowercased) branch name.
     * Iteration order follows self::SUBSTRINGS, so earlier families take priority
     * over later ones when a name contains substrings from multiple families.
     */
    public static function familyFor(string $branchName): ?string
    {
        $lc = strtolower($branchName);
        foreach (self::SUBSTRINGS as $code => $needles) {
            foreach ($needles as $needle) {
                if (str_contains($lc, $needle)) {
                    return $code;
                }
            }
        }

        return null;
    }

    /**
     * @param  array<int,string>  $familyCodes
     * @return array<int,int> Branch IDs whose family is in $familyCodes, scoped to the given course.
     */
    public static function expandToBranchIds(array $familyCodes, int $courseId): array
    {
        $rows = Branch::where('course_id', $courseId)->get(['id', 'name']);
        $ids = [];
        foreach ($rows as $b) {
            if (in_array(self::familyFor($b->name), $familyCodes, true)) {
                $ids[] = $b->id;
            }
        }

        return $ids;
    }
}
