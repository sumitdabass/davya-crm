<?php

namespace Tests\Feature;

use App\Models\Student;
use App\Models\StudentField;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlanOptionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_plan_field_has_new_options(): void
    {
        $options = StudentField::where('key', 'plan')->value('options');
        $values = collect($options)->pluck('value')->all();
        $this->assertEqualsCanonicalizing(
            ['Sitting', 'Counselling Online', 'Counselling Offline'],
            $values
        );
    }

    /**
     * Regression: the `plan` column was a narrow enum('Online','Offline','All')
     * while the dropdown submitted the new values, so every insert 500'd with
     * "Data truncated for column 'plan'". The column must accept the new values.
     */
    public function test_student_persists_new_plan_value(): void
    {
        $student = Student::factory()->create(['plan' => 'Counselling Online']);

        $this->assertSame('Counselling Online', $student->fresh()->plan);
        $this->assertDatabaseHas('students', [
            'id' => $student->id,
            'plan' => 'Counselling Online',
        ]);
    }
}
