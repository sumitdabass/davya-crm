<?php

namespace Tests\Unit\Dashboard;

use App\Dashboard\Card;
use App\Dashboard\CardRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CardRegistryTest extends TestCase
{
    use RefreshDatabase;

    public function test_static_list_cards_are_always_registered(): void
    {
        $this->seed();

        $ids = array_map(fn (Card $c) => $c->id(), CardRegistry::all());

        $this->assertContains('today_meetings', $ids);
        $this->assertContains('today_payments', $ids);
        $this->assertContains('stuck_leads', $ids);
        $this->assertContains('re_entry_candidates', $ids);
        $this->assertContains('seat_fee_pending', $ids);
    }

    public function test_find_returns_card_by_id(): void
    {
        $this->seed();
        $card = CardRegistry::find('today_meetings');
        $this->assertNotNull($card);
        $this->assertSame('today_meetings', $card->id());
    }

    public function test_find_returns_null_for_unknown_id(): void
    {
        $this->seed();
        $this->assertNull(CardRegistry::find('nonexistent_card'));
    }
}
