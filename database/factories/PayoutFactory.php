<?php

namespace Database\Factories;

use App\Models\Payout;
use App\Models\Student;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class PayoutFactory extends Factory
{
    protected $model = Payout::class;

    public function definition(): array
    {
        return [
            'student_id' => Student::factory(),
            'payee_type' => 'college',
            'payee_name' => $this->faker->company(),
            'amount' => $this->faker->numberBetween(1000, 50000),
            'status' => 'to_pay',
            'paid_at' => null,
            'notes' => null,
            'recorded_by_user_id' => User::factory(),
        ];
    }

    public function paid(): static
    {
        return $this->state(fn () => ['status' => 'paid', 'paid_at' => now()]);
    }
}
