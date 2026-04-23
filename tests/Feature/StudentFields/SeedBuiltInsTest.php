<?php
namespace Tests\Feature\StudentFields;

use App\Models\StudentField;
use App\Models\StudentFieldSection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SeedBuiltInsTest extends TestCase
{
    use RefreshDatabase;

    public function test_built_in_sections_and_fields_seeded(): void
    {
        $this->assertSame(2, StudentFieldSection::count(), 'Identity + Academic seeded');
        $identity = StudentFieldSection::where('name', 'Identity')->first();
        $academic = StudentFieldSection::where('name', 'Academic')->first();
        $this->assertNotNull($identity);
        $this->assertNotNull($academic);

        $expected = [
            'phone' => ['Identity', true, 'phone', 'text'],
            'name' => ['Identity', true, 'name', 'text'],
            'father_name' => ['Identity', false, 'father_name', 'text'],
            'phone_2' => ['Identity', false, 'phone_2', 'text'],
            'category' => ['Identity', false, 'category', 'dropdown'],
            'state' => ['Identity', false, 'state', 'text'],
            'course' => ['Academic', false, 'course', 'text'],
            'final_course' => ['Academic', false, 'final_course', 'text'],
        ];

        foreach ($expected as $key => [$sectionName, $required, $col, $type]) {
            $field = StudentField::where('key', $key)->first();
            $this->assertNotNull($field, "Built-in '$key' missing");
            $this->assertTrue($field->is_built_in);
            $this->assertSame($col, $field->built_in_column);
            $this->assertSame($type, $field->type);
            $this->assertSame($required, (bool) $field->is_required);
            $this->assertSame($sectionName, $field->section->name);
        }

        // category options
        $cat = StudentField::where('key', 'category')->first();
        $values = collect($cat->options)->pluck('value')->all();
        $this->assertEqualsCanonicalizing(['Delhi', 'Outside'], $values);
    }
}
