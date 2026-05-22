<?php
namespace Database\Factories;

use App\Models\AiConversation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class AiConversationFactory extends Factory
{
    protected $model = AiConversation::class;
    public function definition(): array {
        return [
            'user_id'         => User::factory(),
            'title'           => $this->faker->sentence(4),
            'started_at'      => now(),
            'last_message_at' => now(),
        ];
    }
}
