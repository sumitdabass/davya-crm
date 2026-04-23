<?php
// tests/Feature/Pipeline/PipelineStageModelTest.php
namespace Tests\Feature\Pipeline;

use App\Models\Pipeline;
use App\Models\Stage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PipelineStageModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_pipeline_hasmany_stages(): void
    {
        $p = Pipeline::create(['name' => 'Test', 'is_default' => true]);
        $s = Stage::create(['pipeline_id' => $p->id, 'name' => 'First', 'stage_type' => 'OPEN', 'display_order' => 1]);
        $this->assertSame(1, $p->stages()->count());
        $this->assertSame('First', $p->stages->first()->name);
        $this->assertSame($p->id, $s->pipeline->id);
    }

    public function test_stage_scope_by_type(): void
    {
        $p = Pipeline::create(['name' => 'T', 'is_default' => true]);
        Stage::create(['pipeline_id' => $p->id, 'name' => 'A', 'stage_type' => 'OPEN', 'display_order' => 1]);
        Stage::create(['pipeline_id' => $p->id, 'name' => 'B', 'stage_type' => 'CLOSED_WON', 'display_order' => 2]);
        Stage::create(['pipeline_id' => $p->id, 'name' => 'C', 'stage_type' => 'CLOSED_LOST', 'display_order' => 3]);
        $this->assertSame(1, Stage::openStages()->count());
        $this->assertSame(1, Stage::wonStages()->count());
        $this->assertSame(1, Stage::lostStages()->count());
    }
}
