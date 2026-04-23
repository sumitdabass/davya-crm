<?php
// tests/Feature/Pipeline/StageTransitionEngineTest.php
namespace Tests\Feature\Pipeline;

use App\Models\Pipeline;
use App\Models\Student;
use App\Services\Pipeline\StageTransitionEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StageTransitionEngineTest extends TestCase
{
    use RefreshDatabase;

    private function studentInStage(string $stageName, array $overrides = []): Student
    {
        $stageId = Pipeline::default()->stages()->where('name',$stageName)->value('id');
        $ownerId = \App\Models\User::factory()->create()->id;
        return Student::create(array_merge([
            'name'=>'T','phone'=>'9' . mt_rand(100000000, 999999999),
            'owner_id'=>$ownerId,'referrer_id'=>$ownerId,
            'lead_source'=>'t','stage'=>$stageName,'stage_id'=>$stageId,
        ], $overrides));
    }

    public function test_closed_transition_without_reason_returns_hard_error(): void
    {
        $engine = app(StageTransitionEngine::class);
        $s = $this->studentInStage('Meeting Done');
        $closedId = Pipeline::default()->stages()->where('name','Closed')->value('id');
        $out = $engine->forStageChange($s, $closedId);
        $this->assertNotEmpty($out['hard']);
        $this->assertStringContainsString('close_reason', implode(' ', $out['hard']));
    }

    public function test_closed_transition_with_reason_passes(): void
    {
        $engine = app(StageTransitionEngine::class);
        $s = $this->studentInStage('Meeting Done', ['close_reason' => 'Not Interested']);
        $closedId = Pipeline::default()->stages()->where('name','Closed')->value('id');
        $out = $engine->forStageChange($s, $closedId);
        $this->assertEmpty($out['hard']);
    }

    public function test_reentry_from_closed_without_reason_returns_hard(): void
    {
        $engine = app(StageTransitionEngine::class);
        $s = $this->studentInStage('Closed');
        $meetingDoneId = Pipeline::default()->stages()->where('name','Meeting Done')->value('id');
        $out = $engine->forStageChange($s, $meetingDoneId);
        $this->assertNotEmpty($out['hard']);
        $this->assertStringContainsString('re_entry_reason', implode(' ', $out['hard']));
    }

    public function test_meeting_scheduled_without_future_meeting_returns_soft(): void
    {
        $engine = app(StageTransitionEngine::class);
        $s = $this->studentInStage('Lead Captured');
        $msId = Pipeline::default()->stages()->where('name','Meeting Scheduled')->value('id');
        $out = $engine->forStageChange($s, $msId);
        $this->assertEmpty($out['hard']);
        $this->assertNotEmpty($out['soft']);
    }

    public function test_meeting_scheduled_with_future_meeting_no_warnings(): void
    {
        $engine = app(StageTransitionEngine::class);
        $s = $this->studentInStage('Lead Captured');
        $s->meetings()->create([
            'scheduled_at' => now()->addDay(),
            'status' => 'scheduled',
            'owner_id' => $s->owner_id,
            'created_by_id' => $s->owner_id,
        ]);
        $msId = Pipeline::default()->stages()->where('name','Meeting Scheduled')->value('id');
        $out = $engine->forStageChange($s, $msId);
        $this->assertEmpty($out['soft']);
    }
}
