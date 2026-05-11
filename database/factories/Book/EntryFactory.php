<?php

namespace Database\Factories\Book;

use App\Models\Book\Company;
use App\Models\Book\Entry;
use App\Models\Book\FiscalYear;
use App\Models\Book\Section;
use Illuminate\Database\Eloquent\Factories\Factory;

class EntryFactory extends Factory
{
    protected $model = Entry::class;

    public function definition(): array
    {
        $c = Company::factory()->create();
        $fy = FiscalYear::factory()->create(['company_id' => $c->id]);
        $s = Section::factory()->create(['company_id' => $c->id]);

        return [
            'company_id' => $c->id,
            'fiscal_year_id' => $fy->id,
            'section_id' => $s->id,
            'title' => $this->faker->name(),
        ];
    }
}
