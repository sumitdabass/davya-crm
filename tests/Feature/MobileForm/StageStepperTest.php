<?php

namespace Tests\Feature\MobileForm;

use App\Filament\Resources\StudentResource\Pages\EditStudent;
use App\Models\Pipeline;
use App\Models\StageTransitionCondition;
use App\Models\StageTransitionRule;
use App\Models\Student;
use App\Models\User;
use App\Services\Pipeline\PipelineConfig;
use App\Services\Pipeline\StageTransitionEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class StageStepperTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $this->seed();
        $u = User::where('email', 'sumit@davya.local')->first();
        $u->update(['must_change_password' => false]);
        $this->actingAs($u);

        return $u;
    }

    public function test_stepper_renders_all_pipeline_stages_on_create(): void
    {
        $this->admin();
        $names = app(PipelineConfig::class)->stageNames();

        $component = Livewire::test(\App\Filament\Resources\StudentResource\Pages\CreateStudent::class);
        foreach ($names as $name) {
            $component->assertSee($name);
        }
    }

    public function test_setting_stage_updates_state_like_the_old_select(): void
    {
        $admin = $this->admin();
        $student = Student::factory()->create([
            'owner_id' => $admin->id,
            'referrer_id' => $admin->id,
            'stage' => 'Lead Captured',
        ]);

        // Equivalent to a stepper tap: $wire.set('data.stage', ...) on the live field.
        Livewire::test(EditStudent::class, ['record' => $student->getKey()])
            ->set('data.stage', 'Meeting Scheduled')
            ->assertSet('data.stage', 'Meeting Scheduled');
    }

    public function test_hard_blocked_transition_reverts_stage_on_edit(): void
    {
        $admin = $this->admin();

        $pipeline = Pipeline::default();
        $leadCapturedId = $pipeline->stages()->where('name', 'Lead Captured')->value('id');
        $advanceReceivedId = $pipeline->stages()->where('name', 'Advance Received')->value('id');

        // Student starts on 'Lead Captured' with no deal_amount set (factory leaves it null).
        $student = Student::factory()->create([
            'owner_id' => $admin->id,
            'referrer_id' => $admin->id,
            'stage' => 'Lead Captured',
            'stage_id' => $leadCapturedId,
            'deal_amount' => null,
        ]);

        // ── HARD rule: block entry into 'Advance Received' unless deal_amount is set. ──
        // (Same StageTransitionRule + StageTransitionCondition wiring the engine reads;
        //  pattern mirrors tests/Feature/Pipeline/ConditionEvaluatorTest.php FIELD_CHECK conds.)
        $rule = StageTransitionRule::create([
            'pipeline_id' => $pipeline->id,
            'name' => 'Advance requires deal amount',
            'from_stage_id' => null, // any source stage
            'to_stage_id' => $advanceReceivedId,
            'severity' => StageTransitionRule::SEV_HARD,
            'is_active' => true,
        ]);
        StageTransitionCondition::create([
            'rule_id' => $rule->id,
            'condition_type' => StageTransitionCondition::TYPE_FIELD_CHECK,
            'field_or_relation' => 'deal_amount',
            'operator' => 'is_not_empty',
            'value' => null,
            'display_order' => 0,
        ]);

        // Sanity: the engine must report a genuine non-empty hard block for this transition.
        $out = app(StageTransitionEngine::class)->forStageChange($student->fresh(), $advanceReceivedId);
        $this->assertNotEmpty($out['hard']);

        // Tapping the 'Advance Received' step runs the identical afterStateUpdated closure,
        // which calls $set('stage', $record->getOriginal('stage')) on a hard block.
        Livewire::test(EditStudent::class, ['record' => $student->getKey()])
            ->set('data.stage', 'Advance Received')
            ->assertSet('data.stage', 'Lead Captured');
    }
}
