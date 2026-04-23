<?php
namespace Tests\Unit\Models;

use App\Models\Student;
use App\Models\StudentField;
use App\Models\StudentFieldSection;
use App\Models\StudentFieldValue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StudentFieldValueTest extends TestCase
{
    use RefreshDatabase;

    private function field(string $type, array $opts = []): StudentField
    {
        $section = StudentFieldSection::firstOrCreate(['name' => 'X'], ['position' => 0]);
        return StudentField::create(array_merge([
            'section_id' => $section->id,
            'key' => $type . '_' . uniqid(),
            'label' => ucfirst($type),
            'type' => $type,
            'is_required' => false,
            'is_built_in' => false,
            'position' => 0,
        ], $opts));
    }

    private function student(): Student
    {
        return Student::factory()->create();
    }

    public function test_text_value_round_trip(): void
    {
        $v = StudentFieldValue::create(['student_id' => $this->student()->id, 'student_field_id' => $this->field('text')->id, 'value_text' => 'hello']);
        $this->assertSame('hello', $v->fresh()->value_text);
    }

    public function test_number_value_round_trip(): void
    {
        $v = StudentFieldValue::create(['student_id' => $this->student()->id, 'student_field_id' => $this->field('number')->id, 'value_number' => 92.5]);
        $this->assertSame('92.5000', (string) $v->fresh()->value_number);
    }

    public function test_date_value_round_trip(): void
    {
        $v = StudentFieldValue::create(['student_id' => $this->student()->id, 'student_field_id' => $this->field('date')->id, 'value_date' => '2009-05-12']);
        $this->assertSame('2009-05-12', $v->fresh()->value_date->toDateString());
    }

    public function test_multiselect_value_round_trip(): void
    {
        $v = StudentFieldValue::create(['student_id' => $this->student()->id, 'student_field_id' => $this->field('multiselect', ['options' => [['value' => 'a', 'label' => 'A'], ['value' => 'b', 'label' => 'B']]])->id, 'value_json' => ['a', 'b']]);
        $this->assertSame(['a', 'b'], $v->fresh()->value_json);
    }

    public function test_unique_constraint_per_student_field(): void
    {
        $s = $this->student();
        $f = $this->field('text');
        StudentFieldValue::create(['student_id' => $s->id, 'student_field_id' => $f->id, 'value_text' => 'first']);
        $this->expectException(\Illuminate\Database\QueryException::class);
        StudentFieldValue::create(['student_id' => $s->id, 'student_field_id' => $f->id, 'value_text' => 'second']);
    }

    public function test_student_has_field_values_relation(): void
    {
        $s = $this->student();
        StudentFieldValue::create(['student_id' => $s->id, 'student_field_id' => $this->field('text')->id, 'value_text' => 'a']);
        StudentFieldValue::create(['student_id' => $s->id, 'student_field_id' => $this->field('number')->id, 'value_number' => 5]);
        $this->assertCount(2, $s->fresh()->fieldValues);
    }
}
