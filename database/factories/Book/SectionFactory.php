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
        // Slug defaults to a unique random token so factory-built sections
        // never collide with the 5 sections auto-seeded by CompanyObserver
        // (salary, rent, loan, assets, expense). Tests that need one of
        // those specific slugs should look them up via the company instead
        // of trying to create a duplicate.
        $slug = 'sec-'.$this->faker->unique()->lexify('??????');

        return [
            'company_id' => Company::factory(),
            'slug' => $slug,
            'name' => ucfirst($slug),
            'kind' => 'generic',
            'sort_order' => 1,
        ];
    }
}
