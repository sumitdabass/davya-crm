<?php
namespace Tests\Unit\StudentFields;

use App\Models\Student;
use App\Models\StudentField;
use App\Models\StudentFieldSection;
use App\Models\StudentFieldValue;
use App\StudentFields\KanbanExtrasFormatter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KanbanExtrasFormatterTest extends TestCase
{
    use RefreshDatabase;

    public function test_format_returns_label_value_pairs_for_show_in_kanban_fields(): void
    {
        $section = StudentFieldSection::create(['name' => 'X', 'position' => 999]);
        $a = StudentField::create(['section_id' => $section->id, 'key' => 'marks_kanban', 'label' => 'Marks', 'type' => 'number', 'is_required' => false, 'is_built_in' => false, 'show_in_kanban' => true, 'position' => 0]);
        $b = StudentField::create(['section_id' => $section->id, 'key' => 'board_kanban', 'label' => 'Board', 'type' => 'text', 'is_required' => false, 'is_built_in' => false, 'show_in_kanban' => true, 'position' => 1]);
        $student = Student::factory()->create(['preference_r1' => 'BCA']);
        StudentFieldValue::create(['student_id' => $student->id, 'student_field_id' => $a->id, 'value_number' => 91]);
        StudentFieldValue::create(['student_id' => $student->id, 'student_field_id' => $b->id, 'value_text' => 'CBSE']);

        $formatter = new KanbanExtrasFormatter();
        $pairs = $formatter->format($student);
        $this->assertSame(['Marks: 91', 'Board: CBSE'], $pairs);
    }

    public function test_format_caps_at_three_pairs(): void
    {
        $section = StudentFieldSection::create(['name' => 'X', 'position' => 999]);
        for ($i = 0; $i < 5; $i++) {
            StudentField::create(['section_id' => $section->id, 'key' => "fcap{$i}", 'label' => "F{$i}", 'type' => 'text', 'is_required' => false, 'is_built_in' => false, 'show_in_kanban' => true, 'position' => $i]);
        }
        $student = Student::factory()->create(['preference_r1' => 'BCA']);
        foreach (StudentField::where('show_in_kanban', true)->get() as $field) {
            StudentFieldValue::create(['student_id' => $student->id, 'student_field_id' => $field->id, 'value_text' => 'v']);
        }
        $pairs = (new KanbanExtrasFormatter())->format($student);
        $this->assertCount(3, $pairs, 'kanban extras must cap at 3 (per spec)');
    }

    public function test_warn_returns_true_when_more_than_three_kanban_fields_enabled(): void
    {
        $section = StudentFieldSection::create(['name' => 'X', 'position' => 999]);
        for ($i = 0; $i < 4; $i++) {
            StudentField::create(['section_id' => $section->id, 'key' => "fwarn{$i}", 'label' => "F{$i}", 'type' => 'text', 'is_required' => false, 'is_built_in' => false, 'show_in_kanban' => true, 'position' => $i]);
        }
        $this->assertTrue((new KanbanExtrasFormatter())->shouldWarnTooManyEnabled());
    }
}
