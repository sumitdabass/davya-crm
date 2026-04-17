<?php

namespace App\Services;

use App\Models\Student;

class PipelineSummary
{
    public const STAGES = [
        'Lead Captured',
        'Meeting Scheduled',
        'Meeting Done',
        'Onboarded',
        'University Registration',
        'Counselling In Progress',
        'Seat Allotted',
        'Full Payment Received',
        'Admission Confirmed',
        'Closed',
    ];

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
