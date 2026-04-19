<?php

namespace Database\Factories;

use App\Models\LedgerEntry;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\LedgerEntry>
 */
class LedgerEntryFactory extends Factory
{
    protected $model = LedgerEntry::class;

    public function definition(): array
    {
        return [
            'account'      => $this->faker->randomElement(['davya', 'nikhil', 'sumit']),
            'delta_amount' => $this->faker->randomFloat(2, -50000, 50000),
            'source_type'  => $this->faker->randomElement(['payment', 'expense', 'investment']),
            'source_id'    => $this->faker->numberBetween(1, 9999),
            'note'         => $this->faker->sentence(),
            'created_at'   => now(),
        ];
    }
}
