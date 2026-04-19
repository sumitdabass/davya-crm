<?php

declare(strict_types=1);

namespace Tests\Unit\Finance;

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
}
