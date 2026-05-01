<?php

namespace App\Services\Rank;

use App\Services\Finance\GeminiClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class GeminiCounsellor
{
    public function __construct(private GeminiClient $gemini) {}

    /**
     * Generate a 1–2 sentence counselling note for one college.
     *
     * @param  array{
     *     institute_name: string,
     *     rank: int,
     *     region: string,
     *     year: int,
     *     branches: array<int, array{branch_name: string, shift: ?string, bucket: string, cushion_pct: int, prediction_max: int, sliding_max: ?int, r3_max: ?int, yoy_delta_pct: ?int, seat_count: ?int}>,
     *     branch_filter_hash: string,
     * }  $ctx
     */
    public function note(array $ctx): ?string
    {
        $rankBucket = (int) floor($ctx['rank'] / 1000);
        $cacheKey = sprintf(
            'rank_note:%s:%d:%s:%s:%d',
            md5(strtolower($ctx['institute_name'])),
            $ctx['year'],
            $ctx['region'],
            $ctx['branch_filter_hash'],
            $rankBucket,
        );

        return Cache::remember($cacheKey, now()->addHours(24), function () use ($ctx) {
            try {
                return $this->callGemini($ctx);
            } catch (Throwable $e) {
                Log::warning('rank counsellor gemini failed', ['err' => $e->getMessage()]);

                return null;
            }
        });
    }

    private function callGemini(array $ctx): ?string
    {
        $systemPrompt = 'You are an IPU B.Tech admission counsellor in Delhi. Given a student\'s rank, region, and the eligible branches at one college, write 1–2 sentences in plain English advising the student on this college: which branch to prefer, any volatility risk (>30% YoY), seat-count caveats. No markdown, no emojis, no college name repetition, no hype.';

        $userJson = [
            'student_rank' => $ctx['rank'],
            'student_region' => $ctx['region'],
            'year' => $ctx['year'],
            'college' => $ctx['institute_name'],
            'eligible_branches' => array_map(fn ($b) => [
                'branch' => $b['branch_name'],
                'shift' => $b['shift'],
                'bucket' => $b['bucket'],
                'cushion_pct' => $b['cushion_pct'],
                'prediction_max' => $b['prediction_max'],
                'sliding_max' => $b['sliding_max'],
                'r3_max' => $b['r3_max'],
                'yoy_delta_pct' => $b['yoy_delta_pct'],
                'seat_count' => $b['seat_count'],
            ], $ctx['branches']),
        ];

        $text = $this->gemini->generate($systemPrompt, $userJson);
        $text = trim($text);

        return $text === '' ? null : $text;
    }
}
