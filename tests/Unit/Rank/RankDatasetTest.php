<?php

namespace Tests\Unit\Rank;

use App\Rank\RankDataset;
use Tests\TestCase;

class RankDatasetTest extends TestCase
{
    /** @test */
    public function maps_tokens_to_university_codes_and_labels(): void
    {
        $this->assertSame(['IPU'], RankDataset::universityCodes('ipu'));
        $this->assertSame(['JAC'], RankDataset::universityCodes('dtu'));
        $this->assertSame('IPU', RankDataset::label('ipu'));
        $this->assertSame('DTU', RankDataset::label('dtu'));
        $this->assertTrue(RankDataset::courseFixedToBtech('dtu'));
        $this->assertFalse(RankDataset::courseFixedToBtech('ipu'));
        $this->assertSame(['ipu', 'dtu'], RankDataset::tokens());
    }
}
