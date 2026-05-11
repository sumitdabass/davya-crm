<?php

namespace Tests\Feature\Books;

use App\Models\Book\Entry;
use App\Models\Book\EntryPayment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EntryPaymentTest extends TestCase
{
    use RefreshDatabase;

    public function test_computes_paid_from_sum_of_out_direction_payments(): void
    {
        $e = Entry::factory()->create(['salary_amount' => 1200000]);

        EntryPayment::create([
            'entry_id' => $e->id,
            'amount' => 200000,
            'direction' => 'out',
            'mode' => 'bank',
            'occurred_on' => '2025-05-01',
        ]);
        EntryPayment::create([
            'entry_id' => $e->id,
            'amount' => 300000,
            'direction' => 'out',
            'mode' => 'cash',
            'occurred_on' => '2025-06-01',
        ]);

        $this->assertSame(500000.0, (float) $e->fresh()->paid);
    }

    public function test_computes_received_back_from_in_direction_payments(): void
    {
        $e = Entry::factory()->create(['loan_amount' => 1000000]);

        EntryPayment::create([
            'entry_id' => $e->id,
            'amount' => 100000,
            'direction' => 'in',
            'mode' => 'bank',
            'occurred_on' => '2025-07-01',
        ]);

        $this->assertSame(100000.0, (float) $e->fresh()->received_back);
    }

    public function test_computes_balance_as_salary_plus_loan_minus_paid_minus_received_back(): void
    {
        $e = Entry::factory()->create(['salary_amount' => 1200000, 'loan_amount' => 0]);

        EntryPayment::create([
            'entry_id' => $e->id,
            'amount' => 200000,
            'direction' => 'out',
            'mode' => 'bank',
            'occurred_on' => '2025-05-01',
        ]);

        $this->assertSame(1000000.0, (float) $e->fresh()->balance);
    }

    public function test_computes_loan_outstanding_as_loan_amount_minus_received_back(): void
    {
        $e = Entry::factory()->create(['loan_amount' => 1000000]);

        EntryPayment::create([
            'entry_id' => $e->id,
            'amount' => 100000,
            'direction' => 'in',
            'mode' => 'bank',
            'occurred_on' => '2025-07-01',
        ]);

        $this->assertSame(900000.0, (float) $e->fresh()->loan_outstanding);
    }

    public function test_rejects_an_invalid_direction(): void
    {
        $e = Entry::factory()->create();

        $this->expectException(\InvalidArgumentException::class);

        EntryPayment::create([
            'entry_id' => $e->id,
            'amount' => 1,
            'direction' => 'sideways',
            'mode' => 'bank',
            'occurred_on' => '2025-05-01',
        ]);
    }
}
