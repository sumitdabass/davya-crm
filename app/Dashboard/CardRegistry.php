<?php

namespace App\Dashboard;

use App\Dashboard\Cards\ListCards\ReEntryCandidatesCard;
use App\Dashboard\Cards\ListCards\SeatFeePendingCard;
use App\Dashboard\Cards\ListCards\StuckLeadsCard;
use App\Dashboard\Cards\ListCards\TodayMeetingsCard;
use App\Dashboard\Cards\ListCards\TodayPaymentsCard;
use App\Dashboard\Cards\Stat\AdmissionsClosedTodayCard;
use App\Dashboard\Cards\Stat\LeadsCapturedTodayCard;
use App\Dashboard\Cards\Stat\MeetingsHeldTodayCard;
use App\Dashboard\Cards\Stat\StageStatCard;
use App\Dashboard\Cards\Stat\TeamStatCard;
use App\Models\Stage;
use App\Models\User;

class CardRegistry
{
    /** @var array<string, Card>|null */
    private static ?array $cache = null;

    /** @return Card[] */
    public static function all(): array
    {
        if (self::$cache === null) {
            self::$cache = self::build();
        }
        return array_values(self::$cache);
    }

    public static function find(string $id): ?Card
    {
        if (self::$cache === null) {
            self::$cache = self::build();
        }
        return self::$cache[$id] ?? null;
    }

    public static function reset(): void
    {
        self::$cache = null;
    }

    /** @return array<string, Card> */
    private static function build(): array
    {
        $static = [
            new TodayMeetingsCard,
            new TodayPaymentsCard,
            new StuckLeadsCard,
            new ReEntryCandidatesCard,
            new SeatFeePendingCard,
            new MeetingsHeldTodayCard,
            new LeadsCapturedTodayCard,
            new AdmissionsClosedTodayCard,
        ];

        $dynamic = Stage::orderBy('display_order')
            ->orderBy('id')
            ->get()
            ->map(fn (Stage $s) => new StageStatCard($s))
            ->all();

        // Team cards — one per (head, metric) pair. Only heads with at least one team member.
        $teamCards = [];
        $heads = User::role('head')
            ->whereHas('teamMembers')
            ->get();
        foreach ($heads as $head) {
            foreach ([
                TeamStatCard::METRIC_LEADS_CAPTURED,
                TeamStatCard::METRIC_MEETINGS_HELD,
                TeamStatCard::METRIC_ADMISSIONS_CLOSED,
            ] as $metric) {
                $teamCards[] = new TeamStatCard($head, $metric);
            }
        }

        $cards = [...$static, ...$dynamic, ...$teamCards];

        $byId = [];
        foreach ($cards as $card) {
            $byId[$card->id()] = $card;
        }
        return $byId;
    }
}
