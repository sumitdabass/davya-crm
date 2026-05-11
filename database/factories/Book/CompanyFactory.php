<?php

namespace Database\Factories\Book;

use App\Models\Book\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

class CompanyFactory extends Factory
{
    protected $model = Company::class;

    public function definition(): array
    {
        $name = $this->faker->company();

        return [
            'name' => $name,
            'slug' => str()->slug($name).'-'.$this->faker->unique()->numberBetween(1, 99999),
        ];
    }
}
