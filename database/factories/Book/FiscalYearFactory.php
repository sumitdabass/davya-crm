<?php

namespace Database\Factories\Book;

use App\Models\Book\Company;
use App\Models\Book\FiscalYear;
use Illuminate\Database\Eloquent\Factories\Factory;

class FiscalYearFactory extends Factory
{
    protected $model = FiscalYear::class;

    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'start_date' => '2025-04-01',
            'end_date' => '2026-03-31',
            'label' => '2025-26',
        ];
    }
}
