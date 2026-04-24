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
        // 7 sections mirror the 7 tabs of the live StudentResource form.
        $this->assertSame(7, StudentFieldSection::count(), 'Identity, Academic, Source & Stage, Deal, Counselling, History, Closure seeded');
        foreach (['Identity', 'Academic', 'Source & Stage', 'Deal', 'Counselling', 'History', 'Closure'] as $name) {
            $this->assertNotNull(StudentFieldSection::where('name', $name)->first(), "Section '$name' missing");
        }

        // Representative built-ins across sections (not exhaustive — full list is in migrations 010300 + 010400).
        $expected = [
            // Identity
            'phone'         => ['Identity',       true,  'phone',         'text'],
            'name'          => ['Identity',       true,  'name',          'text'],
            'father_name'   => ['Identity',       false, 'father_name',   'text'],
            'phone_2'       => ['Identity',       false, 'phone_2',       'text'],
            'email'         => ['Identity',       false, 'email',         'email'],
            // Academic (category + state moved here in 010400)
            'category'      => ['Academic',       false, 'category',      'dropdown'],
            'state'         => ['Academic',       false, 'state',         'text'],
            'course'        => ['Academic',       false, 'course',        'text'],
            'preference_r1' => ['Academic',       true,  'preference_r1', 'text'],
            // Source & Stage
            'owner_id'         => ['Source & Stage', true,  'owner_id',         'dropdown'],
            'stage'            => ['Source & Stage', true,  'stage',            'dropdown'],
            'student_response' => ['Source & Stage', false, 'student_response', 'dropdown'],
            // Deal
            'deal_amount' => ['Deal', false, 'deal_amount', 'number'],
            'plan'        => ['Deal', false, 'plan',        'dropdown'],
            // Counselling
            'is_ipu_registered' => ['Counselling', false, 'is_ipu_registered', 'checkbox'],
            'ipu_login_code'    => ['Counselling', false, 'ipu_login_code',    'text'],
            // Closure
            'close_reason'   => ['Closure', false, 'close_reason',   'dropdown'],
            'refund_amount'  => ['Closure', false, 'refund_amount',  'number'],
            're_entry_reason'=> ['Closure', false, 're_entry_reason','textarea'],
        ];

        foreach ($expected as $key => [$sectionName, $required, $col, $type]) {
            $field = StudentField::where('key', $key)->first();
            $this->assertNotNull($field, "Built-in '$key' missing");
            $this->assertTrue($field->is_built_in, "Built-in '$key' should be is_built_in");
            $this->assertSame($col, $field->built_in_column);
            $this->assertSame($type, $field->type);
            $this->assertSame($required, (bool) $field->is_required);
            $this->assertSame($sectionName, $field->section->name, "Built-in '$key' is in wrong section");
        }

        // Static-dropdown options seeded in labeled format.
        foreach (['category' => ['Delhi','Outside'], 'student_response' => ['Ready','Not Interested','Needs Time'], 'plan' => ['Online','Offline','All']] as $key => $expectedValues) {
            $field = StudentField::where('key', $key)->first();
            $values = collect($field->options)->pluck('value')->all();
            $this->assertEqualsCanonicalizing($expectedValues, $values, "$key options mismatch");
        }
    }
}
