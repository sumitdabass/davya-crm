<?php

namespace Database\Factories\Book;

use App\Models\Book\Company;
use App\Models\Book\FiscalYear;
use App\Models\Book\IncomeEntry;
use Illuminate\Database\Eloquent\Factories\Factory;

class IncomeEntryFactory extends Factory
{
    protected $model = IncomeEntry::class;

    public function definition(): array
    {
        $c = Company::factory()->create();

        return [
            'company_id' => $c->id,
            'fiscal_year_id' => FiscalYear::factory()->create(['company_id' => $c->id]),
            'occurred_on' => '2025-05-15',
            'source' => $this->faker->company(),
            'amount' => 100000,
        ];
    }
}
