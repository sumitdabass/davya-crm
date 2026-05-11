<?php

namespace Database\Factories\Book;

use App\Models\Book\Entry;
use App\Models\Book\EntryPayment;
use Illuminate\Database\Eloquent\Factories\Factory;

class EntryPaymentFactory extends Factory
{
    protected $model = EntryPayment::class;

    public function definition(): array
    {
        return [
            'entry_id' => Entry::factory(),
            'occurred_on' => '2025-05-01',
            'amount' => 10000,
            'direction' => 'out',
            'mode' => 'bank',
        ];
    }
}
