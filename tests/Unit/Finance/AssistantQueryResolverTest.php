<?php

declare(strict_types=1);

namespace Tests\Unit\Finance;

use App\Models\Expense;
use App\Models\Investment;
use App\Models\LedgerEntry;
use App\Models\Payment;
use App\Models\Student;
use App\Services\Finance\AssistantQueryResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AssistantQueryResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_empty_shape_for_unknown_intent_falls_through_to_freeform(): void
    {
        $resolver = new AssistantQueryResolver();
        $result = $resolver->resolve('nonsense_intent', null, null);

        $this->assertArrayHasKey('summary', $result);
        $this->assertArrayHasKey('rows', $result);
    }

    public function test_spend_by_category_filters_expenses_and_returns_summary_plus_rows(): void
    {
        Expense::factory()->create(['category' => 'Marketing', 'amount' => 5000, 'paid_at' => '2026-04-15 12:00:00']);
        Expense::factory()->create(['category' => 'Marketing', 'amount' => 3200, 'paid_at' => '2026-04-10 12:00:00']);
        Expense::factory()->create(['category' => 'Travel',    'amount' => 1000, 'paid_at' => '2026-04-12 12:00:00']);

        $resolver = new AssistantQueryResolver();
        $result = $resolver->resolve(
            'spend_by_category',
            ['from' => '2026-04-01', 'to' => '2026-04-19'],
            ['category' => 'Marketing']
        );

        $this->assertSame(2, $result['summary']['count']);
        $this->assertSame(8200.0, $result['summary']['total_amount']);
        $this->assertCount(2, $result['rows']);
    }

    public function test_payments_by_student_returns_rows_for_matching_phone(): void
    {
        $student = Student::factory()->create(['phone' => '9991110001']);
        Payment::factory()->count(3)->create(['student_id' => $student->id, 'amount' => 1000, 'type' => 'partial']);
        Payment::factory()->create(['amount' => 9999, 'type' => 'partial']); // noise: different student

        $resolver = new AssistantQueryResolver();
        $result = $resolver->resolve('payments_by_student', null, ['student_phone' => '9991110001']);

        $this->assertSame(3, $result['summary']['count']);
        $this->assertSame(3000.0, $result['summary']['total_amount']);
        $this->assertCount(3, $result['rows']);
    }

    public function test_ledger_balance_sums_delta_amount_by_account(): void
    {
        LedgerEntry::factory()->create(['account' => 'nikhil', 'delta_amount' =>  10000.00]);
        LedgerEntry::factory()->create(['account' => 'nikhil', 'delta_amount' =>   5000.00]);
        LedgerEntry::factory()->create(['account' => 'nikhil', 'delta_amount' =>  -2000.00]);
        LedgerEntry::factory()->create(['account' => 'davya',  'delta_amount' =>   7777.00]); // noise

        $resolver = new AssistantQueryResolver();
        $result = $resolver->resolve('ledger_balance', null, ['account' => 'nikhil']);

        $this->assertSame(13000.0, $result['summary']['balance']);
        $this->assertSame(3, $result['summary']['entry_count']);
    }

    public function test_recent_captures_unions_payments_expenses_investments_with_most_recent_first(): void
    {
        Payment::factory()->create(['received_at'      => '2026-04-18 10:00:00']);
        Expense::factory()->create(['paid_at'          => '2026-04-19 09:00:00']);
        Investment::factory()->create(['transacted_at' => '2026-04-17 14:00:00']);

        $resolver = new AssistantQueryResolver();
        $result = $resolver->resolve('recent_captures', ['from' => '2026-04-15', 'to' => '2026-04-19'], null);

        $this->assertSame(3, $result['summary']['count']);
        $this->assertSame('expense', $result['rows'][0]['kind']); // 04-19 is most recent
    }
}
