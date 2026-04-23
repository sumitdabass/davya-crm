<?php
namespace Tests\Unit\Models;

use App\Models\StudentFieldSection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StudentFieldSectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_section_table_exists_with_expected_columns(): void
    {
        $section = StudentFieldSection::create(['name' => 'Identity', 'position' => 0]);
        $this->assertDatabaseHas('student_field_sections', ['id' => $section->id, 'name' => 'Identity', 'position' => 0]);
        $this->assertNotNull($section->fresh()->created_at);
    }
}
