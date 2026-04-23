<?php
// tests/Feature/Pipeline/ConditionEvaluatorTest.php
namespace Tests\Feature\Pipeline;

use App\Models\Student;
use App\Models\StageTransitionCondition;
use App\Services\Pipeline\ConditionEvaluator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConditionEvaluatorTest extends TestCase
{
    use RefreshDatabase;

    private function student(array $overrides = []): Student
    {
        $ownerId = \App\Models\User::factory()->create()->id;
        $base = [
            'name'=>'E','phone'=>'9' . mt_rand(100000000, 999999999),
            'owner_id'=>$ownerId,'referrer_id'=>$ownerId,
            'lead_source'=>'t',
            'stage'=>'Lead Captured',
            'stage_id'=>\App\Models\Pipeline::default()->stages()->where('name','Lead Captured')->value('id'),
        ];
        return Student::create(array_merge($base, $overrides));
    }

    private function cond(string $type, string $field, string $op, $value = null): StageTransitionCondition
    {
        return new StageTransitionCondition([
            'condition_type' => $type, 'field_or_relation' => $field,
            'operator' => $op, 'value' => $value, 'display_order' => 0,
        ]);
    }

    public function test_field_is_not_empty_passes_when_set(): void
    {
        $eval = app(ConditionEvaluator::class);
        $s = $this->student(['close_reason' => 'Not Interested']);
        $this->assertTrue($eval->passes($this->cond('FIELD_CHECK','close_reason','is_not_empty'), $s));
    }

    public function test_field_is_not_empty_fails_when_null(): void
    {
        $eval = app(ConditionEvaluator::class);
        $s = $this->student();
        $this->assertFalse($eval->passes($this->cond('FIELD_CHECK','close_reason','is_not_empty'), $s));
    }

    public function test_field_gte_operator(): void
    {
        $eval = app(ConditionEvaluator::class);
        $s = $this->student(['deal_amount' => 100000]);
        $this->assertTrue($eval->passes($this->cond('FIELD_CHECK','deal_amount','>=', ['rhs' => 50000]), $s));
        $this->assertFalse($eval->passes($this->cond('FIELD_CHECK','deal_amount','>=', ['rhs' => 200000]), $s));
    }

    public function test_has_relation_scheduled_meeting_in_future(): void
    {
        $eval = app(ConditionEvaluator::class);
        $s = $this->student();
        // Seed a future meeting
        $s->meetings()->create([
            'scheduled_at' => now()->addDay(),
            'status' => 'scheduled',
            'owner_id' => $s->owner_id,
            'created_by_id' => $s->owner_id,
        ]);
        $c = $this->cond('HAS_RELATION','meetings','has_where', [
            'status' => 'scheduled', 'scheduled_at_gte' => 'now', 'count_min' => 1,
        ]);
        $this->assertTrue($eval->passes($c, $s));
    }

    public function test_has_relation_returns_false_when_no_rows(): void
    {
        $eval = app(ConditionEvaluator::class);
        $s = $this->student();
        $c = $this->cond('HAS_RELATION','meetings','has_where', [
            'status' => 'scheduled', 'count_min' => 1,
        ]);
        $this->assertFalse($eval->passes($c, $s));
    }
}
