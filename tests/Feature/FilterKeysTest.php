<?php

namespace Tests\Feature;

use App\Filters\FilterKeys;
use Tests\TestCase;

class FilterKeysTest extends TestCase
{
    public function test_students_list_url_emits_filament_table_filter_shape(): void
    {
        $this->assertSame(
            '/admin/students?tableFilters%5Bstuck%5D%5BisActive%5D=1',
            FilterKeys::studentsListUrl(FilterKeys::STUCK),
        );
        $this->assertSame(
            '/admin/students?tableFilters%5Bre_entry%5D%5BisActive%5D=1',
            FilterKeys::studentsListUrl(FilterKeys::RE_ENTRY),
        );
        $this->assertSame(
            '/admin/students?tableFilters%5Bseat_fee_pending%5D%5BisActive%5D=1',
            FilterKeys::studentsListUrl(FilterKeys::SEAT_FEE_PENDING),
        );
    }

    public function test_kanban_url_uses_short_key_aliases(): void
    {
        // Kanban URLs are short — seat_fee_pending becomes ?seat_fee=1 etc.
        $this->assertSame('/admin/kanban?stuck=1', FilterKeys::kanbanUrl(FilterKeys::STUCK));
        $this->assertSame('/admin/kanban?re_entry=1', FilterKeys::kanbanUrl(FilterKeys::RE_ENTRY));
        $this->assertSame('/admin/kanban?seat_fee=1', FilterKeys::kanbanUrl(FilterKeys::SEAT_FEE_PENDING));
    }

    public function test_card_ids_pinned(): void
    {
        // Dashboard CardRegistry IDs are the third shape — pinned here so a
        // rename in CardRegistry breaks this assertion and forces a sweep.
        $this->assertSame('stuck_leads',         FilterKeys::CARD_IDS[FilterKeys::STUCK]);
        $this->assertSame('re_entry_candidates', FilterKeys::CARD_IDS[FilterKeys::RE_ENTRY]);
        $this->assertSame('seat_fee_pending',    FilterKeys::CARD_IDS[FilterKeys::SEAT_FEE_PENDING]);
    }

    public function test_unknown_semantic_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        FilterKeys::studentsListUrl('bogus');
    }
}
