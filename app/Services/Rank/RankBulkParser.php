<?php

namespace App\Services\Rank;

use App\Models\Rank\Branch;
use App\Models\Rank\Course;
use App\Models\Rank\Cutoff;
use App\Models\Rank\Institute;
use App\Models\Rank\Seat;
use Illuminate\Support\Facades\Auth;

class RankBulkParser
{
    /** Parse "Min Rank - X Max Rank - Y" cell. Returns [min,max] or null. */
    public function parseRankCell(?string $cell): ?array
    {
        if ($cell === null) {
            return null;
        }
        $cell = trim($cell);
        if ($cell === '' || $cell === '-' || stripos($cell, 'not applicable') !== false) {
            return null;
        }
        if (! preg_match('/Min\s*Rank\s*-\s*(\d+).*?Max\s*Rank\s*-\s*(\d+)/is', $cell, $m)) {
            return null;
        }
        $min = (int) $m[1];
        $max = (int) $m[2];
        if ($min > $max) {
            [$min, $max] = [$max, $min];
        }

        return [$min, $max];
    }

    public function parseSlidingCell(?string $cell): ?int
    {
        if ($cell === null) {
            return null;
        }
        $cell = trim($cell);
        if ($cell === '' || $cell === '-' || stripos($cell, 'not applicable') !== false) {
            return null;
        }
        $rank = (int) preg_replace('/[^\d]/', '', $cell);

        return $rank > 0 ? $rank : null;
    }

    /** @return array{0:?string,1:?string} [normalized branch name, shift|null] */
    public function normalizeBranch(string $raw): array
    {
        $s = trim(preg_replace('/\s+/', ' ', $raw));
        $shift = null;
        $patterns = [
            '/\s*\(Shift\s*(I{1,2})\)$/i' => 1,
            '/\s*\((I{1,2})\s*Shift\)$/i' => 1,
            '/\s+(I{1,2})-\s*Shift$/i' => 1,
        ];
        foreach ($patterns as $regex => $group) {
            if (preg_match($regex, $s, $m)) {
                $shift = strtoupper($m[$group]);
                $s = preg_replace($regex, '', $s);
                break;
            }
        }
        $s = trim(preg_replace('/\s+&\s+/', ' & ', str_replace([' and '], [' & '], $s)));

        return [$s, $shift];
    }

    /**
     * @param  array{round:string,university_id:int,course_id:int,qualifying_exam_id:int,admission_process_id:int,year:int}  $ctx
     * @return array{imported:int,skipped:int,errors:array<int,string>}
     */
    public function importCutoffs(string $tsv, array $ctx): array
    {
        $stats = ['imported' => 0, 'skipped' => 0, 'errors' => []];
        $lines = preg_split('/\r?\n/', trim($tsv));
        if (! $lines) {
            return $stats;
        }

        $first = $lines[0];
        if (stripos($first, 'institute') !== false && stripos($first, 'branch') !== false) {
            array_shift($lines);
        }

        $userId = Auth::id();
        $isSliding = $ctx['round'] === 'sliding';

        foreach ($lines as $i => $line) {
            $line = rtrim($line);
            if ($line === '') {
                continue;
            }
            $parts = explode("\t", $line);
            $rawInst = trim($parts[0] ?? '');
            $rawBranch = trim($parts[1] ?? '');
            if ($rawInst === '' || $rawBranch === '') {
                $stats['skipped']++;

                continue;
            }

            $inst = $this->resolveInstitute($ctx['university_id'], $rawInst);
            [$branchName, $shift] = $this->normalizeBranch($rawBranch);
            $branch = $this->resolveBranch($ctx['course_id'], $branchName);

            if ($isSliding) {
                $rank = $this->parseSlidingCell($parts[2] ?? '');
                if ($rank === null) {
                    $stats['skipped']++;

                    continue;
                }
                $this->upsertCutoff($ctx, $inst->id, $branch->id, $shift, 'delhi', 1, $rank, $userId);
                $stats['imported']++;
            } else {
                $delhi = $this->parseRankCell($parts[2] ?? '');
                $os = $this->parseRankCell($parts[3] ?? '');
                if ($delhi !== null) {
                    $this->upsertCutoff($ctx, $inst->id, $branch->id, $shift, 'delhi', $delhi[0], $delhi[1], $userId);
                    $stats['imported']++;
                }
                if ($os !== null) {
                    $this->upsertCutoff($ctx, $inst->id, $branch->id, $shift, 'outside_delhi', $os[0], $os[1], $userId);
                    $stats['imported']++;
                }
                if ($delhi === null && $os === null) {
                    $stats['skipped']++;
                }
            }
        }

        return $stats;
    }

    /**
     * @param  array{university_id:int,year:int}  $ctx
     * @return array{imported:int,skipped:int,errors:array<int,string>}
     */
    public function importSeats(string $tsv, array $ctx): array
    {
        $stats = ['imported' => 0, 'skipped' => 0, 'errors' => []];
        $lines = preg_split('/\r?\n/', trim($tsv));
        if (! $lines) {
            return $stats;
        }

        $first = $lines[0];
        if (stripos($first, 'institute') !== false || stripos($first, 'college') !== false) {
            array_shift($lines);
        }

        $userId = Auth::id();
        $courseCache = [];

        foreach ($lines as $line) {
            $line = rtrim($line);
            if ($line === '') {
                continue;
            }
            $parts = explode("\t", $line);
            $rawInst = trim($parts[0] ?? '');
            $rawCourse = trim($parts[1] ?? '');
            $rawBranch = trim($parts[2] ?? '');
            $seats = (int) preg_replace('/[^\d]/', '', trim($parts[3] ?? ''));

            if ($rawInst === '' || $rawCourse === '' || $rawBranch === '' || $seats <= 0) {
                $stats['skipped']++;

                continue;
            }

            $cacheKey = strtolower($rawCourse);
            $course = $courseCache[$cacheKey] ?? Course::where('university_id', $ctx['university_id'])
                ->whereRaw('LOWER(name) = ?', [strtolower($rawCourse)])
                ->orWhere(function ($q) use ($ctx, $rawCourse) {
                    $q->where('university_id', $ctx['university_id'])
                        ->whereRaw('LOWER(code) = ?', [strtolower($rawCourse)]);
                })
                ->first();
            if (! $course) {
                $stats['errors'][] = "Course not found: {$rawCourse}";
                $stats['skipped']++;

                continue;
            }
            $courseCache[$cacheKey] = $course;

            $inst = $this->resolveInstitute($ctx['university_id'], $rawInst);
            [$branchName] = $this->normalizeBranch($rawBranch);
            $branch = $this->resolveBranch($course->id, $branchName);

            Seat::updateOrCreate(
                [
                    'university_id' => $ctx['university_id'],
                    'course_id' => $course->id,
                    'year' => $ctx['year'],
                    'institute_id' => $inst->id,
                    'branch_id' => $branch->id,
                ],
                [
                    'seat_count' => $seats,
                    'updated_by' => $userId,
                    'created_by' => $userId,
                ]
            );
            $stats['imported']++;
        }

        return $stats;
    }

    private function resolveInstitute(int $universityId, string $rawName): Institute
    {
        $name = trim(preg_replace('/\s+/', ' ', $rawName));

        return Institute::firstOrCreate(
            ['university_id' => $universityId, 'name' => $name],
        );
    }

    private function resolveBranch(int $courseId, string $name): Branch
    {
        return Branch::firstOrCreate(
            ['course_id' => $courseId, 'name' => $name],
        );
    }

    private function upsertCutoff(array $ctx, int $instId, int $branchId, ?string $shift, string $region, int $min, int $max, ?int $userId): void
    {
        Cutoff::updateOrCreate(
            [
                'university_id' => $ctx['university_id'],
                'course_id' => $ctx['course_id'],
                'qualifying_exam_id' => $ctx['qualifying_exam_id'],
                'admission_process_id' => $ctx['admission_process_id'],
                'year' => $ctx['year'],
                'round' => $ctx['round'],
                'institute_id' => $instId,
                'branch_id' => $branchId,
                'shift' => $shift,
                'region' => $region,
            ],
            [
                'min_rank' => $min,
                'max_rank' => $max,
                'source' => $ctx['round'] === 'sliding' ? 'validated' : 'official',
                'updated_by' => $userId,
                'created_by' => $userId,
            ]
        );
    }
}
