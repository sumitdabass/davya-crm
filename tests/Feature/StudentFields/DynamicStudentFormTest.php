<?php
namespace Tests\Feature\StudentFields;

use App\Models\Student;
use App\Models\StudentField;
use App\Models\StudentFieldSection;
use App\Models\StudentFieldValue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DynamicStudentFormTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $this->seed();
        $section = StudentFieldSection::firstOrCreate(['name' => 'Demographics'], ['position' => 99]);
        $dob = StudentField::create(['section_id' => $section->id, 'key' => 'dob', 'label' => 'DOB', 'type' => 'date', 'is_required' => false, 'is_built_in' => false, 'position' => 0]);
        $marks = StudentField::create(['section_id' => $section->id, 'key' => 'marks', 'label' => 'Marks', 'type' => 'number', 'is_required' => false, 'is_built_in' => false, 'position' => 1]);
        $board = StudentField::create(['section_id' => $section->id, 'key' => 'board', 'label' => 'Board', 'type' => 'dropdown', 'is_required' => false, 'is_built_in' => false, 'options' => [['value' => 'cbse', 'label' => 'CBSE']], 'position' => 2]);
        return compact('dob', 'marks', 'board');
    }

    public function test_hydrate_pulls_existing_values_per_type(): void
    {
        ['dob' => $dob, 'marks' => $marks, 'board' => $board] = $this->fixture();
        $student = Student::factory()->create();
        StudentFieldValue::create(['student_id' => $student->id, 'student_field_id' => $dob->id, 'value_date' => '2009-05-12']);
        StudentFieldValue::create(['student_id' => $student->id, 'student_field_id' => $marks->id, 'value_number' => 92.5]);
        StudentFieldValue::create(['student_id' => $student->id, 'student_field_id' => $board->id, 'value_text' => 'cbse']);

        $hydrated = (new \App\StudentFields\StudentFormDynamicTrait\Hydrator())->hydrate($student);
        $this->assertSame('2009-05-12', $hydrated['dob']);
        $this->assertSame(92.5, (float) $hydrated['marks']);
        $this->assertSame('cbse', $hydrated['board']);
    }

    public function test_persist_writes_values_typed_correctly(): void
    {
        ['dob' => $dob, 'marks' => $marks, 'board' => $board] = $this->fixture();
        $student = Student::factory()->create();

        (new \App\StudentFields\StudentFormDynamicTrait\Persister())->persist($student, [
            'dob' => '2010-06-15',
            'marks' => '88.25',
            'board' => 'cbse',
        ]);

        $dobValue = StudentFieldValue::where(['student_id' => $student->id, 'student_field_id' => $dob->id])->first();
        $this->assertSame('2010-06-15', $dobValue->value_date->toDateString());
        $marksValue = StudentFieldValue::where(['student_id' => $student->id, 'student_field_id' => $marks->id])->first();
        $this->assertSame('88.2500', (string) $marksValue->value_number);
        $boardValue = StudentFieldValue::where(['student_id' => $student->id, 'student_field_id' => $board->id])->first();
        $this->assertSame('cbse', $boardValue->value_text);
    }

    public function test_persist_upserts_existing_value_does_not_create_duplicate(): void
    {
        ['dob' => $dob] = $this->fixture();
        $student = Student::factory()->create();
        StudentFieldValue::create(['student_id' => $student->id, 'student_field_id' => $dob->id, 'value_date' => '2009-01-01']);

        (new \App\StudentFields\StudentFormDynamicTrait\Persister())->persist($student, ['dob' => '2010-01-01']);

        $this->assertSame(1, StudentFieldValue::where('student_id', $student->id)->count());
        $this->assertSame('2010-01-01', StudentFieldValue::where('student_id', $student->id)->first()->value_date->toDateString());
    }
}
