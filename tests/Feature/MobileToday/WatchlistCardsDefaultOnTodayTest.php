<?php

namespace Tests\Feature\MobileToday;

use App\Dashboard\Cards\ListCards\ReEntryCandidatesCard;
use App\Dashboard\Cards\ListCards\SeatFeePendingCard;
use App\Dashboard\Cards\ListCards\StuckLeadsCard;
use Tests\TestCase;

class WatchlistCardsDefaultOnTodayTest extends TestCase
{
    public function test_watchlist_cards_default_on_today_and_dashboard(): void
    {
        foreach ([new StuckLeadsCard, new SeatFeePendingCard, new ReEntryCandidatesCard] as $card) {
            $this->assertTrue($card->isDefaultOn('today'), $card->id().' should default-on today');
            $this->assertTrue($card->isDefaultOn('dashboard'), $card->id().' should still default-on dashboard');
        }
    }
}
