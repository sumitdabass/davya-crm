<?php
namespace Database\Factories;

use App\Models\Investment;
use Illuminate\Database\Eloquent\Factories\Factory;

class InvestmentFactory extends Factory
{
    protected $model = Investment::class;

    public function definition(): array
    {
        return [
            'asset_name' => $this->faker->randomElement(['Tata Motors','Real Estate #12','Binance BTC']),
            'amount'     => $this->faker->numberBetween(10000, 500000),
            'direction'  => $this->faker->randomElement(['in','out']),
            'transacted_at' => now(),
            'slack_message_id' => 'CTEST.'.$this->faker->unique()->numerify('##########.######'),
            'raw_input'  => $this->faker->sentence(),
        ];
    }
}
