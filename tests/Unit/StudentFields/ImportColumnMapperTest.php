<?php
namespace Tests\Unit\StudentFields;

use App\Models\StudentField;
use App\Models\StudentFieldSection;
use App\StudentFields\ImportColumnMapper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ImportColumnMapperTest extends TestCase
{
    use RefreshDatabase;

    public function test_template_columns_include_built_ins_and_show_in_import_customs(): void
    {
        // T4 already seeds 8 built-ins with show_in_import=true; add a custom dob shown, plus a custom hidden.
        $section = StudentFieldSection::firstOrCreate(['name' => 'Demographics'], ['position' => 99]);
        StudentField::create(['section_id' => $section->id, 'key' => 'dob_import', 'label' => 'DOB', 'type' => 'date', 'is_required' => false, 'is_built_in' => false, 'show_in_import' => true, 'position' => 0]);
        StudentField::create(['section_id' => $section->id, 'key' => 'hidden_import', 'label' => 'Hidden', 'type' => 'text', 'is_required' => false, 'is_built_in' => false, 'show_in_import' => false, 'position' => 1]);

        $headers = (new ImportColumnMapper())->templateHeaders();
        $this->assertContains('phone', $headers);
        $this->assertContains('name', $headers);
        $this->assertContains('dob_import', $headers);
        $this->assertNotContains('hidden_import', $headers);
    }
}
