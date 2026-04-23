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

    public function test_rule_has_conditions_and_from_to_relations(): void
    {
        $p = Pipeline::create(['name'=>'P','is_default'=>true]);
        $from = Stage::create(['pipeline_id'=>$p->id,'name'=>'A','stage_type'=>'OPEN','display_order'=>1]);
        $to   = Stage::create(['pipeline_id'=>$p->id,'name'=>'B','stage_type'=>'CLOSED_LOST','display_order'=>2]);

        $rule = \App\Models\StageTransitionRule::create([
            'pipeline_id'=>$p->id,'name'=>'r1',
            'from_stage_id'=>$from->id,'to_stage_id'=>$to->id,
            'severity'=>'HARD','is_active'=>true,
        ]);
        $rule->conditions()->create([
            'condition_type'=>'FIELD_CHECK','field_or_relation'=>'close_reason',
            'operator'=>'is_not_empty','value'=>null,'display_order'=>0,
        ]);

        $this->assertSame(1, $rule->conditions()->count());
        $this->assertSame('A', $rule->fromStage->name);
        $this->assertSame('B', $rule->toStage->name);
        $this->assertSame(1, $p->transitionRules()->count());
    }
}
