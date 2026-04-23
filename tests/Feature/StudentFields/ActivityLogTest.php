<?php
namespace Tests\Feature\StudentFields;

use App\Models\Student;
use App\Models\StudentField;
use App\Models\StudentFieldSection;
use App\Models\StudentFieldValue;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class ActivityLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_value_create_logs_activity(): void
    {
        $this->seed();
        $admin = User::where('email', 'sumit@davya.local')->first();
        $this->actingAs($admin);

        $section = StudentFieldSection::firstOrCreate(['name' => 'XLog'], ['position' => 999]);
        $field = StudentField::create(['section_id' => $section->id, 'key' => 'dob_log', 'label' => 'DOB', 'type' => 'date', 'is_required' => false, 'is_built_in' => false, 'position' => 0]);
        $student = Student::factory()->create(['preference_r1' => 'BCA']);

        StudentFieldValue::create(['student_id' => $student->id, 'student_field_id' => $field->id, 'value_date' => '2009-01-01']);

        $log = Activity::where('subject_type', StudentFieldValue::class)->latest()->first();
        $this->assertNotNull($log);
        $this->assertStringContainsString('DOB', $log->description);
    }

    public function test_value_update_logs_old_to_new(): void
    {
        $this->seed();
        $admin = User::where('email', 'sumit@davya.local')->first();
        $this->actingAs($admin);

        $section = StudentFieldSection::firstOrCreate(['name' => 'XLog'], ['position' => 999]);
        $field = StudentField::create(['section_id' => $section->id, 'key' => 'marks_log', 'label' => 'Marks', 'type' => 'number', 'is_required' => false, 'is_built_in' => false, 'position' => 0]);
        $student = Student::factory()->create(['preference_r1' => 'BCA']);

        $v = StudentFieldValue::create(['student_id' => $student->id, 'student_field_id' => $field->id, 'value_number' => 80]);
        $v->update(['value_number' => 90]);

        $log = Activity::where('subject_type', StudentFieldValue::class)->latest('id')->first();
        $this->assertStringContainsString('Marks', $log->description);
        $this->assertStringContainsString('80', $log->description);
        $this->assertStringContainsString('90', $log->description);
    }
}
