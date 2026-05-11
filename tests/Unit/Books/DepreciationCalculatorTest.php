<?php

namespace Tests\Unit\Books;

use App\Books\Services\DepreciationCalculator;
use App\Models\Book\Asset;
use App\Models\Book\Company;
use App\Models\Book\Entry;
use App\Models\Book\FiscalYear;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DepreciationCalculatorTest extends TestCase
{
    use RefreshDatabase;

    private function makeAsset(array $assetAttrs = []): array
    {
        $c = Company::factory()->create();
        $fy25 = FiscalYear::factory()->create([
            'company_id' => $c->id,
            'start_date' => '2025-04-01',
            'end_date' => '2026-03-31',
            'label' => '2025-26',
        ]);
        $fy26 = FiscalYear::factory()->create([
            'company_id' => $c->id,
            'start_date' => '2026-04-01',
            'end_date' => '2027-03-31',
            'label' => '2026-27',
        ]);
        // 'assets' (kind=asset) is auto-seeded on every Company by the
        // CompanyObserver — reuse it instead of trying to create a duplicate.
        $s = $c->sections()->where('slug', 'assets')->firstOrFail();
        $e = Entry::factory()->create([
            'company_id' => $c->id,
            'section_id' => $s->id,
            'fiscal_year_id' => $fy25->id,
            'title' => 'Car',
        ]);
        $asset = Asset::create(array_merge([
            'entry_id' => $e->id,
            'original_value' => 300000,
            'dep_percent' => 20,
            'dep_years' => 5,
            'dep_started_at' => '2025-04-01',
            'method' => 'straight_line',
        ], $assetAttrs));

        return [$asset, $fy25, $fy26];
    }

    public function test_computes_straight_line_dep_for_full_fy(): void
    {
        [$asset, $fy25] = $this->makeAsset();

        $this->assertSame(60000.0, (float) (new DepreciationCalculator())->yearlyDepFor($asset, $fy25));
    }

    public function test_prorates_straight_line_dep_when_started_mid_fy(): void
    {
        [$asset, $fy25] = $this->makeAsset(['dep_started_at' => '2025-10-01']);
        $expected = 300000 * 0.20 * (182 / 365);

        $this->assertEqualsWithDelta(
            $expected,
            (float) (new DepreciationCalculator())->yearlyDepFor($asset, $fy25),
            1.0
        );
    }

    public function test_computes_wdv_dep_on_declining_book_value(): void
    {
        [$asset, $fy25, $fy26] = $this->makeAsset(['method' => 'wdv']);
        $calc = new DepreciationCalculator();

        $this->assertSame(60000.0, (float) $calc->yearlyDepFor($asset, $fy25));
        // year 2 = (300k - 60k) * 20% = 48000
        $this->assertSame(48000.0, (float) $calc->yearlyDepFor($asset, $fy26));
    }

    public function test_accumulates_dep_across_closed_prior_fys(): void
    {
        [$asset, $fy25, $fy26] = $this->makeAsset();
        $fy25->update(['is_closed' => true]);

        $this->assertSame(
            120000.0,
            (float) (new DepreciationCalculator())->accumulatedDepThrough($asset, $fy26)
        );
    }

    public function test_computes_book_value_at_end_of_fy(): void
    {
        [$asset, $fy25] = $this->makeAsset();

        $this->assertSame(
            240000.0,
            (float) (new DepreciationCalculator())->bookValueAtEndOf($asset, $fy25)
        );
    }
}
