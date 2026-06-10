<?php

namespace Tests\Feature\MobilePipeline;

use App\Filament\Pages\KanbanBoard;
use App\Services\Pipeline\PipelineConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderedStagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_ordered_stage_names_match_pipeline_display_order(): void
    {
        $this->seed();

        $expected = app(PipelineConfig::class)->stageNames(); // already display_order

        $names = (new KanbanBoard())->orderedStageNames();

        $this->assertSame($expected, $names);
        $this->assertNotEmpty($names);
        // The Guided sheet computes next = index+1, back = index-1 in JS from this array,
        // so display order is the contract this test guards.
    }
}
