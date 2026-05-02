<?php

namespace App\Services\Rank;

use App\Models\Rank\Branch;

class BranchFamilies
{
    /**
     * Family code → display label. Order is the order shown in the picker.
     */
    private const FAMILIES = [
        'cs'              => 'Computer Science (CS / CSE)',
        'it'              => 'Information Technology',
        'aiml'            => 'AI & Machine Learning',
        'aids'            => 'AI & Data Science',
        'iiot'            => 'Industrial IoT',
        'ece'             => 'Electronics & Communication (ECE)',
        'eee'             => 'Electrical & Electronics (EEE / EE)',
        'instrumentation' => 'Instrumentation',
        'mechanical'      => 'Mechanical / Robotics',
        'civil_arch'      => 'Civil / Architecture',
        'chem_energy'     => 'Chemical / Energy / Nano',
    ];

    /** @return array<string, string> Family code → label, in display order. */
    public static function all(): array
    {
        return self::FAMILIES;
    }

    /**
     * Match a branch name to its family. Order matters — earlier checks win.
     * Two specifics:
     *   - "Computer Science & Engineering - AIML" → cs (NOT aiml). CS variants
     *     and dual-degrees stay in the cs family.
     *   - Standalone "Artificial Intelligence & Machine Learning" → aiml,
     *     because it's a distinct B.Tech program at IPU, not a CSE flavour.
     */
    public static function familyFor(string $branchName): ?string
    {
        $lc = strtolower(trim($branchName));

        // 1. CS family — must come before AIML/AIDS so CSE-AIML, CSE-AI-DS,
        //    CSE-Cyber-Security, CSE-IoT-Blockchain, B.Tech (CS), Computer
        //    Science & Applied Mathematics, etc. all bucket here.
        if (str_contains($lc, 'computer science')
            || str_contains($lc, 'cse')
            || preg_match('/\bcs\b/', $lc)) {
            return 'cs';
        }

        // 2. Standalone AIML — matches the full title or the bare token.
        //    CSE-AIML was already caught above.
        if (preg_match('/artificial intelligence.*machine learning/', $lc)
            || preg_match('/\baiml\b/', $lc)) {
            return 'aiml';
        }

        // 3. Standalone AIDS.
        if (preg_match('/artificial intelligence.*data science/', $lc)
            || preg_match('/\baids\b/', $lc)) {
            return 'aids';
        }

        // 4. Industrial IoT.
        if (str_contains($lc, 'industrial internet of things')
            || preg_match('/\biiot\b/', $lc)) {
            return 'iiot';
        }

        // 5. IT — separate family from CS. Catches "Information Technology",
        //    "Information Technology (Dual Degree)", "Information Technology
        //    & Engineering", and a bare " IT " token.
        if (str_contains($lc, 'information technology')
            || preg_match('/\bit\b/', $lc)) {
            return 'it';
        }

        // 6. ECE — Electronics & Communication, ECE-VLSI, ECE Advance Comm,
        //    1st/2nd shift variants. Anything tagged "Electronics" but NOT
        //    "Electrical" lives here.
        if (str_contains($lc, 'electronics & communication')
            || str_contains($lc, 'electronics & comm')
            || str_contains($lc, 'electronics engg')
            || str_contains($lc, 'vlsi')
            || str_contains($lc, 'advance comm')
            || preg_match('/\bece\b/', $lc)) {
            return 'ece';
        }

        // 7. EEE / EE — Electrical & Electronics; treat both abbreviations
        //    as one family.
        if (str_contains($lc, 'electrical')
            || preg_match('/\b(eee|ee)\b/', $lc)) {
            return 'eee';
        }

        // 8. Instrumentation — its own family, doesn't fold into ECE.
        if (str_contains($lc, 'instrumentation')) {
            return 'instrumentation';
        }

        // 9. Mechanical, Mechatronics, Automation & Robotics, Robotics & AI.
        if (str_contains($lc, 'mechanical')
            || str_contains($lc, 'mechatronics')
            || str_contains($lc, 'automation & robotics')
            || str_contains($lc, 'robotics & artificial')) {
            return 'mechanical';
        }

        // 10. Civil + Architecture/3D Modelling.
        if (str_contains($lc, 'civil')
            || str_contains($lc, 'architecture')
            || str_contains($lc, '3d modelling')
            || str_contains($lc, '3d modeling')) {
            return 'civil_arch';
        }

        // 11. Chemical / Bio-chem / Energy / Nanoscience.
        if (str_contains($lc, 'chemical')
            || str_contains($lc, 'energy')
            || str_contains($lc, 'nanoscience')) {
            return 'chem_energy';
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
