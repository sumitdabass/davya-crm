<?php

namespace Tests\Feature\Books;

use App\Models\Book\Company;
use App\Models\Book\FiscalYear;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FiscalYearTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_a_fy_scoped_to_a_company(): void
    {
        $c = Company::factory()->create();

        $fy = FiscalYear::create([
            'company_id' => $c->id,
            'start_date' => '2025-04-01',
            'end_date' => '2026-03-31',
            'label' => '2025-26',
        ]);

        $this->assertFalse($fy->is_closed);
        $this->assertNull($fy->closing_summary);
    }

    public function test_enforces_unique_company_id_label(): void
    {
        $c = Company::factory()->create();

        FiscalYear::create([
            'company_id' => $c->id,
            'start_date' => '2025-04-01',
            'end_date' => '2026-03-31',
            'label' => '2025-26',
        ]);

        $this->expectException(QueryException::class);

        FiscalYear::create([
            'company_id' => $c->id,
            'start_date' => '2025-04-01',
            'end_date' => '2026-03-31',
            'label' => '2025-26',
        ]);
    }

    public function test_casts_closing_summary_as_array(): void
    {
        $c = Company::factory()->create();

        $fy = FiscalYear::create([
            'company_id' => $c->id,
            'start_date' => '2025-04-01',
            'end_date' => '2026-03-31',
            'label' => '2025-26',
            'closing_summary_json' => ['net_pl' => 12345],
        ]);

        $this->assertSame(['net_pl' => 12345], $fy->fresh()->closing_summary);
    }
}
