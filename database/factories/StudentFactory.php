<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Student>
 */
class StudentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'phone' => fake()->unique()->numerify('9#########'),
            'name' => fake()->name(),
            'owner_id' => User::factory(),
            'referrer_id' => User::factory(),
            'lead_source' => 'Factory',
            'stage' => 'Lead Captured',
        ];
    }
}
