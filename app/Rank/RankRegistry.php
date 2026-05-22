<?php

namespace App\Rank;

use App\Models\User;

class RankRegistry
{
    public static function descriptors(): array
    {
        return [
            // Primary daily-use tool
            [
                'key'   => 'lookup',
                'group' => 'primary',
                'title' => 'Rank Lookup',
                'desc'  => 'Predict eligible colleges + branches for a given rank, exam, and category. Cushion %, prediction bucket, AI counsellor notes.',
                'icon'  => 'heroicon-o-magnifying-glass',
                'url'   => '/admin/rank-lookup',
            ],

            // Source data management — rare-use bulk editing
            [
                'key'   => 'universities',
                'group' => 'manage',
                'title' => 'Universities',
                'desc'  => 'University records (name, code, state, official website).',
                'icon'  => 'heroicon-o-building-library',
                'url'   => '/admin/rank/universities',
            ],
            [
                'key'   => 'institutes',
                'group' => 'manage',
                'title' => 'Institutes',
                'desc'  => 'Colleges + institutes affiliated to each university.',
                'icon'  => 'heroicon-o-building-office',
                'url'   => '/admin/rank/institutes',
            ],
            [
                'key'   => 'courses',
                'group' => 'manage',
                'title' => 'Courses',
                'desc'  => 'Courses offered per university (B.Tech, MBA, etc.).',
                'icon'  => 'heroicon-o-academic-cap',
                'url'   => '/admin/rank/courses',
            ],
            [
                'key'   => 'branches',
                'group' => 'manage',
                'title' => 'Branches',
                'desc'  => 'Specialisations / branches inside each course.',
                'icon'  => 'heroicon-o-rectangle-stack',
                'url'   => '/admin/rank/branches',
            ],
            [
                'key'   => 'cutoffs',
                'group' => 'manage',
                'title' => 'Cutoffs',
                'desc'  => 'Historical cutoffs per year / round / region. Bulk-paste import available.',
                'icon'  => 'heroicon-o-chart-bar',
                'url'   => '/admin/rank/cutoffs',
            ],
            [
                'key'   => 'seats',
                'group' => 'manage',
                'title' => 'Seats',
                'desc'  => 'Seat counts per year / branch / institute. Bulk-paste import available.',
                'icon'  => 'heroicon-o-squares-2x2',
                'url'   => '/admin/rank/seats',
            ],
            [
                'key'   => 'qualifying-exams',
                'group' => 'manage',
                'title' => 'Qualifying Exams',
                'desc'  => 'JEE, CUET, CLAT and other entrance exam reference data.',
                'icon'  => 'heroicon-o-pencil-square',
                'url'   => '/admin/rank/qualifying-exams',
            ],
            [
                'key'   => 'admission-processes',
                'group' => 'manage',
                'title' => 'Admission Processes',
                'desc'  => 'Process codes (CSAB, JAC, JoSAA) used in cutoff records.',
                'icon'  => 'heroicon-o-clipboard-document-list',
                'url'   => '/admin/rank/admission-processes',
            ],
        ];
    }

    public static function accessibleFor(?User $user): array
    {
        if ($user === null) {
            return [];
        }

        return self::canAccess($user) ? self::descriptors() : [];
    }

    public static function canAccess(?User $user): bool
    {
        return $user?->hasAnyRole(['admin', 'rank-admin']) ?? false;
    }
}
