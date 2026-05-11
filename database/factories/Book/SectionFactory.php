<?php

namespace Database\Factories\Book;

use App\Models\Book\Company;
use App\Models\Book\Section;
use Illuminate\Database\Eloquent\Factories\Factory;

class SectionFactory extends Factory
{
    protected $model = Section::class;

    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'slug' => 'salary',
            'name' => 'Salary',
            'kind' => 'generic',
            'sort_order' => 1,
        ];
    }
}
