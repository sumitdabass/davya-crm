<?php

namespace Tests\Unit\Rank;

use App\Services\Rank\PredictorContext;
use Tests\TestCase;

class PredictorContextTest extends TestCase
{
    /** @test */
    public function holds_prediction_inputs_with_defaults(): void
    {
        $ctx = new PredictorContext(
            datasetToken: 'dtu',
            rank: 45000,
            region: 'delhi',
            category: 'sc',
            subCategory: 'gender_neutral',
            gender: 'male',
        );

        $this->assertSame('dtu', $ctx->datasetToken);
        $this->assertSame(45000, $ctx->rank);
        $this->assertSame('sc', $ctx->category);
        $this->assertFalse($ctx->isGeneral());
        $this->assertTrue((new PredictorContext('ipu', 1000, 'delhi', 'general'))->isGeneral());
    }
}
