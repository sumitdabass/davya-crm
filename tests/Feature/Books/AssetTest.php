<?php

namespace Tests\Feature\Books;

use App\Models\Book\Asset;
use App\Models\Book\Entry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AssetTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_an_asset_linked_1_to_1_to_an_entry(): void
    {
        $e = Entry::factory()->create(['title' => 'Car']);

        $a = Asset::create([
            'entry_id' => $e->id,
            'original_value' => 300000,
            'dep_percent' => 20,
            'dep_years' => 5,
            'dep_started_at' => '2025-04-01',
            'method' => 'straight_line',
        ]);

        $this->assertSame(300000.0, (float) $a->original_value);
        $this->assertSame('Car', $a->entry->title);
    }

    public function test_enforces_unique_entry_id_1_to_1(): void
    {
        $e = Entry::factory()->create();

        Asset::create([
            'entry_id' => $e->id,
            'original_value' => 100,
            'dep_percent' => 10,
            'dep_years' => 5,
            'dep_started_at' => '2025-04-01',
            'method' => 'straight_line',
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);

        Asset::create([
            'entry_id' => $e->id,
            'original_value' => 100,
            'dep_percent' => 10,
            'dep_years' => 5,
            'dep_started_at' => '2025-04-01',
            'method' => 'straight_line',
        ]);
    }

    public function test_rejects_invalid_method(): void
    {
        $e = Entry::factory()->create();

        $this->expectException(\InvalidArgumentException::class);

        Asset::create([
            'entry_id' => $e->id,
            'original_value' => 100,
            'dep_percent' => 10,
            'dep_years' => 5,
            'dep_started_at' => '2025-04-01',
            'method' => 'martian',
        ]);
    }
}
