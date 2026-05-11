<?php

namespace Database\Factories\Book;

use App\Models\Book\Asset;
use App\Models\Book\Entry;
use Illuminate\Database\Eloquent\Factories\Factory;

class AssetFactory extends Factory
{
    protected $model = Asset::class;

    public function definition(): array
    {
        return [
            'entry_id' => Entry::factory(),
            'original_value' => 300000,
            'dep_percent' => 20,
            'dep_years' => 5,
            'dep_started_at' => '2025-04-01',
            'method' => 'straight_line',
        ];
    }
}
