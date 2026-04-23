<?php
namespace Tests\Feature\StudentFields;

use App\Models\Student;
use App\Models\StudentField;
use App\Models\StudentFieldSection;
use App\Models\StudentFieldValue;
use App\StudentFields\ImportColumnMapper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CsvImportCustomFieldsTest extends TestCase
{
    use RefreshDatabase;

    public function test_apply_row_writes_built_in_column_and_field_value(): void
    {
        $this->seed();
        $section = StudentFieldSection::firstOrCreate(['name' => 'Demographics'], ['position' => 99]);
        StudentField::create(['section_id' => $section->id, 'key' => 'dob_apply', 'label' => 'DOB', 'type' => 'date', 'is_required' => false, 'is_built_in' => false, 'show_in_import' => true, 'position' => 0]);

        $student = Student::factory()->create(['preference_r1' => 'BCA']);
        (new ImportColumnMapper())->applyRow($student, ['dob_apply' => '2009-04-04']);

        $f = StudentField::where('key', 'dob_apply')->first();
        $value = StudentFieldValue::where(['student_id' => $student->id, 'student_field_id' => $f->id])->first();
        $this->assertNotNull($value);
        $this->assertSame('2009-04-04', $value->value_date->toDateString());
    }
}
