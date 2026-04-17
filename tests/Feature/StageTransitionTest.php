<?php

namespace Tests\Feature;

use App\Models\Student;
use App\Services\StageTransitionValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StageTransitionTest extends TestCase
{
    use RefreshDatabase;

    public function test_warning_when_entering_sliding_without_prior_allotment(): void
    {
        $validator = new StageTransitionValidator;
        $student = Student::factory()->create();

        $warnings = $validator->forRoundChange($student, 'Online_Sliding');

        $this->assertContains('Not eligible for Sliding (no prior allotment).', $warnings);
    }

    public function test_close_stage_requires_close_reason(): void
    {
        $validator = new StageTransitionValidator;
        $student = Student::factory()->create(['close_reason' => null]);

        $errors = $validator->forStageChange($student, 'Closed');

        $this->assertContains('close_reason is required when moving to Closed.', $errors);
    }

    public function test_re_entry_requires_re_entry_reason(): void
    {
        $validator = new StageTransitionValidator;
        $student = Student::factory()->create([
            'stage' => 'Closed',
            'close_reason' => 'Other',
            're_entry_reason' => null,
        ]);
        $student->stage = 'Lead Captured';

        $errors = $validator->forStageChange($student, 'Lead Captured');

        $this->assertContains('re_entry_reason is required when re-opening a closed student.', $errors);
    }
}
