<?php

namespace Database\Factories;

use App\Models\Payment;
use App\Models\Student;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Payment>
 */
class PaymentFactory extends Factory
{
    protected $model = Payment::class;

    public function definition(): array
    {
        return [
            'student_id'       => Student::factory(),
            'type'             => $this->faker->randomElement(['advance', 'partial', 'full']),
            'amount'           => $this->faker->numberBetween(1000, 100000),
            'mode'             => $this->faker->randomElement(['cash', 'upi', 'bank_transfer']),
            'reference_number' => null,
            'received_at'      => now(),
            'proof_url'        => null,
            'notes'            => null,
            'slack_message_id' => 'CTEST.'.$this->faker->unique()->numerify('##########.######'),
            'raw_input'        => $this->faker->sentence(),
        ];
    }
}
