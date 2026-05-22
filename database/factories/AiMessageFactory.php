<?php
namespace Database\Factories;

use App\Models\AiConversation;
use App\Models\AiMessage;
use Illuminate\Database\Eloquent\Factories\Factory;

class AiMessageFactory extends Factory
{
    protected $model = AiMessage::class;
    public function definition(): array {
        return [
            'conversation_id' => AiConversation::factory(),
            'role'            => 'user',
            'content'         => $this->faker->sentence(),
            'created_at'      => now(),
        ];
    }
}
