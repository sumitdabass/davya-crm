<?php

namespace App\Services;

use App\Enums\PipelineStage;
use App\Models\Student;
use App\Models\User;

class PipelineSummary
{
    public const STAGES = [
        'Lead Captured', 'Meeting Scheduled', 'Meeting Done', 'Advance Received',
        'MQ', 'Round 1', 'Round 2', 'Round 3', 'Sliding', 'Offline',
        'Seat Allotted', 'Closed',
    ];

    public const STAGE_LEAD_CAPTURED = 'Lead Captured';
    public const STAGE_CLOSED = 'Closed';

    /**
     * @return array<int, array{name:string,count:int,active:int,admitted:int,closed:int}>
     */
    public static function byOwnerAfterCaptured(): array
    {
        return self::aggregateByUserColumn('owner_id');
    }

    /**
     * @return array<int, array{name:string,count:int,active:int,admitted:int,closed:int}>
     */
    public static function byReferrerAfterCaptured(): array
    {
        return self::aggregateByUserColumn('referrer_id');
    }

    /**
     * @return array<int, array{name:string,count:int,active:int,admitted:int,closed:int}>
     */
    private static function aggregateByUserColumn(string $col): array
    {
        $rows = Student::query()
            ->selectRaw(
                "$col as uid, stage, COUNT(*) as c"
            )
            ->whereNotNull($col)
            ->where('stage', '!=', self::STAGE_LEAD_CAPTURED)
            ->groupBy($col, 'stage')
            ->get();

        $agg = [];
        foreach ($rows as $r) {
            $uid = (int) $r->uid;
            if (! isset($agg[$uid])) {
                $agg[$uid] = ['count' => 0, 'active' => 0, 'admitted' => 0, 'closed' => 0];
            }
            $c = (int) $r->c;
            $agg[$uid]['count'] += $c;
            if ($r->stage === self::STAGE_CLOSED) {
                $agg[$uid]['closed'] += $c;
            } elseif ($r->stage === PipelineStage::Closed->value) {
                $agg[$uid]['admitted'] += $c;
            } else {
                $agg[$uid]['active'] += $c;
            }
        }

        if ($agg === []) {
            return [];
        }

        $names = User::whereIn('id', array_keys($agg))->pluck('name', 'id')->all();
        $out = [];
        foreach ($agg as $uid => $row) {
            $out[$uid] = ['name' => $names[$uid] ?? ('#'.$uid)] + $row;
        }
        uasort($out, fn ($a, $b) => $b['count'] <=> $a['count']);

        return $out;
    }

    /**
     * @return array<string, array{count:int,total:float}>
     */
    public static function compute(): array
    {
        $raw = Student::query()
            ->selectRaw('stage, COUNT(*) as count, COALESCE(SUM(deal_amount), 0) as total')
            ->groupBy('stage')
            ->get()
            ->keyBy('stage');

        $out = [];
        foreach (self::STAGES as $stage) {
            $row = $raw->get($stage);
            $out[$stage] = [
                'count' => (int) ($row->count ?? 0),
                'total' => (float) ($row->total ?? 0),
            ];
        }

        return $out;
    }
}
