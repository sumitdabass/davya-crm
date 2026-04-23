<?php
// tests/Feature/Pipeline/SeededPipelineTest.php
namespace Tests\Feature\Pipeline;

use App\Models\Pipeline;
use App\Models\Stage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SeededPipelineTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_pipeline_exists_with_13_stages(): void
    {
        $p = Pipeline::default();
        $this->assertSame('IPU Admission', $p->name);
        $this->assertSame(13, $p->stages()->count());
    }

    public function test_stage_types_distribution(): void
    {
        $p = Pipeline::default();
        $this->assertSame(11, $p->stages()->where('stage_type', Stage::TYPE_OPEN)->count());
        $this->assertSame(1,  $p->stages()->where('stage_type', Stage::TYPE_WON)->count());
        $this->assertSame(1,  $p->stages()->where('stage_type', Stage::TYPE_LOST)->count());
    }

    public function test_complete_payment_received_is_won(): void
    {
        $p = Pipeline::default();
        $won = $p->stages()->where('stage_type', Stage::TYPE_WON)->first();
        $this->assertSame('Complete Payment Received', $won->name);
    }

    public function test_closed_is_lost(): void
    {
        $p = Pipeline::default();
        $lost = $p->stages()->where('stage_type', Stage::TYPE_LOST)->first();
        $this->assertSame('Closed', $lost->name);
    }

    public function test_seat_allotted_is_open(): void
    {
        $p = Pipeline::default();
        $sa = $p->stages()->where('name', 'Seat Allotted')->firstOrFail();
        $this->assertSame(Stage::TYPE_OPEN, $sa->stage_type);
    }

    public function test_four_default_rules_seeded(): void
    {
        $p = \App\Models\Pipeline::default();
        $this->assertSame(4, $p->transitionRules()->count());
    }

    public function test_closed_requires_close_reason(): void
    {
        $p = \App\Models\Pipeline::default();
        $closed = $p->stages()->where('name','Closed')->firstOrFail();
        $rule = $p->transitionRules()->where('to_stage_id', $closed->id)->whereNull('from_stage_id')->firstOrFail();
        $this->assertSame('HARD', $rule->severity);
        $cond = $rule->conditions()->firstOrFail();
        $this->assertSame('FIELD_CHECK', $cond->condition_type);
        $this->assertSame('close_reason', $cond->field_or_relation);
        $this->assertSame('is_not_empty', $cond->operator);
    }

    public function test_reentry_from_closed_rule_uses_null_to_stage(): void
    {
        $p = \App\Models\Pipeline::default();
        $closed = $p->stages()->where('name','Closed')->firstOrFail();
        $rule = $p->transitionRules()->where('from_stage_id', $closed->id)->whereNull('to_stage_id')->firstOrFail();
        $cond = $rule->conditions()->firstOrFail();
        $this->assertSame('re_entry_reason', $cond->field_or_relation);
    }
}
