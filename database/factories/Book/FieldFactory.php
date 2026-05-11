<?php

namespace Database\Factories\Book;

use App\Models\Book\Company;
use App\Models\Book\Field;
use App\Models\Book\Section;
use Illuminate\Database\Eloquent\Factories\Factory;

class FieldFactory extends Factory
{
    protected $model = Field::class;

    public function definition(): array
    {
        $c = Company::factory()->create();

        return [
            'company_id' => $c->id,
            'section_id' => Section::factory()->create(['company_id' => $c->id])->id,
            'key' => 'pan_'.$this->faker->unique()->randomNumber(),
            'label' => 'PAN',
            'type' => 'text',
            'sort_order' => 1,
        ];
    }
}
