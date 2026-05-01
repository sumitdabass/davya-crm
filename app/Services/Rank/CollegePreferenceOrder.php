<?php

namespace App\Services\Rank;

class CollegePreferenceOrder
{
    /**
     * Lowercase substring → priority weight. Lower = appears first.
     * Iteration order matters: the first matching needle wins. Edit this list
     * to reorder, add, or remove preferred colleges.
     */
    private const PRIORITIES = [
        'university school of information' => 1,    // USICT
        'maharaja agrasen' => 2,                    // MAIT
        'maharaja surajmal' => 3,                   // MSIT
        'bharati vidyapeeth' => 4,                  // BVP
        'bhagwan parshuram' => 5,                   // BPIT
        'vivekananda institute of professional' => 6, // VIPS
        'guru teg bahadur institute' => 7,          // GTBIT
        'akhilesh das gupta' => 8,                  // Dr Akhilesh
        'hmr institute' => 9,                       // HMR
        'guru tegh bahadur 4th centenary' => 10,    // GTB 4th Centenary
        'university school of automation' => 11,    // USAR
        'university school of chemical' => 12,      // USCT
    ];

    /**
     * Returns the priority weight of the first matching needle in PRIORITIES.
     * 999 if no needle matches (falls through to alphabetical sort).
     */
    public static function sortKey(string $instituteName): int
    {
        $lc = strtolower($instituteName);
        foreach (self::PRIORITIES as $needle => $weight) {
            if (str_contains($lc, $needle)) {
                return $weight;
            }
        }

        return 999;
    }
}
