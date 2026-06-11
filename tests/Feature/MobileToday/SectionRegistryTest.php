<?php

namespace Tests\Feature\MobileToday;

use App\Today\SectionRegistry;
use Tests\TestCase;

class SectionRegistryTest extends TestCase
{
    public function test_known_list_cards_have_descriptors(): void
    {
        foreach ([
            'today_meetings', 'payments_to_chase', 'today_payments',
            'stuck_leads', 'seat_fee_pending', 're_entry_candidates',
        ] as $id) {
            $d = SectionRegistry::descriptor($id);
            $this->assertNotNull($d, "$id should have a descriptor");
            $this->assertArrayHasKey('label', $d);
            $this->assertArrayHasKey('icon', $d);
            $this->assertArrayHasKey('urgent', $d);
        }
    }

    public function test_unknown_id_returns_null(): void
    {
        $this->assertNull(SectionRegistry::descriptor('nope'));
    }
}
