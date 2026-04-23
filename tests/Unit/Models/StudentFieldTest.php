<?php
namespace Tests\Unit\Models;

use App\Models\StudentField;
use App\Models\StudentFieldSection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StudentFieldTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_create_custom_text_field(): void
    {
        $section = StudentFieldSection::create(['name' => 'Demographics', 'position' => 0]);
        $field = StudentField::create([
            'section_id' => $section->id,
            'key' => 'dob',
            'label' => 'Date of Birth',
            'type' => 'date',
            'is_required' => false,
            'is_built_in' => false,
            'position' => 0,
        ]);
        $this->assertSame('date', $field->type);
        $this->assertFalse((bool) $field->is_built_in);
        $this->assertNull($field->archived_at);
    }

    public function test_key_must_be_unique(): void
    {
        $section = StudentFieldSection::create(['name' => 'X', 'position' => 0]);
        StudentField::create(['section_id' => $section->id, 'key' => 'dob', 'label' => 'A', 'type' => 'text', 'is_required' => false, 'is_built_in' => false, 'position' => 0]);
        $this->expectException(\Illuminate\Database\QueryException::class);
        StudentField::create(['section_id' => $section->id, 'key' => 'dob', 'label' => 'B', 'type' => 'text', 'is_required' => false, 'is_built_in' => false, 'position' => 1]);
    }

    public function test_active_scope_excludes_archived(): void
    {
        $section = StudentFieldSection::create(['name' => 'X', 'position' => 0]);
        $live = StudentField::create(['section_id' => $section->id, 'key' => 'a', 'label' => 'A', 'type' => 'text', 'is_required' => false, 'is_built_in' => false, 'position' => 0]);
        $arch = StudentField::create(['section_id' => $section->id, 'key' => 'b', 'label' => 'B', 'type' => 'text', 'is_required' => false, 'is_built_in' => false, 'position' => 1, 'archived_at' => now()]);
        $ids = StudentField::active()->pluck('id')->all();
        $this->assertContains($live->id, $ids);
        $this->assertNotContains($arch->id, $ids);
    }

    public function test_built_in_scope(): void
    {
        // The seed migration (T4) already inserts 8 built-ins. Add one more
        // built-in + one custom and assert deltas, not absolute counts.
        $builtInBefore = StudentField::builtIn()->count();
        $customBefore = StudentField::custom()->count();

        $section = StudentFieldSection::create(['name' => 'X', 'position' => 999]);
        StudentField::create(['section_id' => $section->id, 'key' => 'phone_extra', 'label' => 'Phone Extra', 'type' => 'text', 'is_required' => false, 'is_built_in' => true, 'built_in_column' => 'phone_extra', 'position' => 0]);
        StudentField::create(['section_id' => $section->id, 'key' => 'dob', 'label' => 'DOB', 'type' => 'date', 'is_required' => false, 'is_built_in' => false, 'position' => 1]);

        $this->assertSame($builtInBefore + 1, StudentField::builtIn()->count());
        $this->assertSame($customBefore + 1, StudentField::custom()->count());
    }

    public function test_options_cast_as_array(): void
    {
        $section = StudentFieldSection::create(['name' => 'X', 'position' => 0]);
        $field = StudentField::create([
            'section_id' => $section->id,
            'key' => 'board',
            'label' => 'Board',
            'type' => 'dropdown',
            'is_required' => false,
            'is_built_in' => false,
            'options' => [['value' => 'cbse', 'label' => 'CBSE'], ['value' => 'icse', 'label' => 'ICSE']],
            'position' => 0,
        ]);
        $this->assertIsArray($field->fresh()->options);
        $this->assertSame('CBSE', $field->fresh()->options[0]['label']);
    }
}
