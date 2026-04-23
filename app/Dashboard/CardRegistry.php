<?php

namespace App\Dashboard;

use App\Dashboard\Cards\ListCards\ReEntryCandidatesCard;
use App\Dashboard\Cards\ListCards\SeatFeePendingCard;
use App\Dashboard\Cards\ListCards\StuckLeadsCard;
use App\Dashboard\Cards\ListCards\TodayMeetingsCard;
use App\Dashboard\Cards\ListCards\TodayPaymentsCard;

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
        $cards = [
            new TodayMeetingsCard,
            new TodayPaymentsCard,
            new StuckLeadsCard,
            new ReEntryCandidatesCard,
            new SeatFeePendingCard,
            // Stat cards and stage cards added in later tasks.
        ];

        $byId = [];
        foreach ($cards as $card) {
            $byId[$card->id()] = $card;
        }
        return $byId;
    }
}
