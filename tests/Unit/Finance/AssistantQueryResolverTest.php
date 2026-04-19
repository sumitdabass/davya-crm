<?php

declare(strict_types=1);

namespace Tests\Unit\Finance;

use App\Models\Expense;
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
}
