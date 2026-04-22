<?php

namespace Tests\Unit;

use App\Enums\PipelineStage;
use Tests\TestCase;

class PipelineStageTest extends TestCase
{
    public function test_cases_in_canonical_order(): void
    {
        $this->assertSame([
            'Lead Captured', 'Meeting Scheduled', 'Meeting Done', 'Advance Received',
            'MQ', 'Round 1', 'Round 2', 'Round 3', 'Sliding', 'Offline',
            'Seat Allotted', 'Closed',
        ], PipelineStage::values());
    }

    public function test_values_helper_returns_string_values(): void
    {
        $values = PipelineStage::values();
        $this->assertCount(12, $values);
        $this->assertContainsOnly('string', $values);
    }

    public function test_options_returns_label_keyed_array_for_filament(): void
    {
        $opts = PipelineStage::options();
        $this->assertSame('Lead Captured', $opts['Lead Captured']);
        $this->assertSame('Round 1', $opts['Round 1']);
        $this->assertCount(12, $opts);
    }

    public static function roundNameMappingProvider(): array
    {
        return [
            ['Online_R1', PipelineStage::Round1],
            ['S2_R1', PipelineStage::Round1],
            ['Online_R2', PipelineStage::Round2],
            ['Online_R3', PipelineStage::Round3],
            ['S2_R3', PipelineStage::Round3],
            ['Online_Sliding', PipelineStage::Sliding],
            ['Online_Reporting', PipelineStage::Sliding],
            ['Offline_R1', PipelineStage::Offline],
            ['Offline_R2', PipelineStage::Offline],
        ];
    }

    /** @dataProvider roundNameMappingProvider */
    public function test_from_round_name_maps_correctly(string $roundName, PipelineStage $expected): void
    {
        $this->assertSame($expected, PipelineStage::fromRoundName($roundName));
    }

    public function test_from_round_name_returns_null_for_unknown(): void
    {
        $this->assertNull(PipelineStage::fromRoundName('Bogus'));
    }

    public function test_round_stages_helper(): void
    {
        $this->assertSame([
            PipelineStage::Round1, PipelineStage::Round2, PipelineStage::Round3,
            PipelineStage::Sliding, PipelineStage::Offline,
        ], PipelineStage::roundStages());
    }
}
