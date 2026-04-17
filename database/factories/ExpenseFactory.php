<?php
namespace Database\Factories;

use App\Models\Expense;
use Illuminate\Database\Eloquent\Factories\Factory;

class ExpenseFactory extends Factory
{
    protected $model = Expense::class;

    public function definition(): array
    {
        return [
            'amount' => $this->faker->numberBetween(100, 50000),
            'category' => $this->faker->randomElement(['Marketing','Rent','Food','Office']),
            'description' => $this->faker->sentence(),
            'paid_at' => now(),
            'slack_message_id' => 'CTEST.'.$this->faker->unique()->numerify('##########.######'),
            'raw_input' => $this->faker->sentence(),
        ];
    }
}
