<?php
namespace Tests\Feature\StudentFields;

use App\Filament\Resources\StudentResource\Pages\ListStudents;
use App\Models\Student;
use App\Models\StudentField;
use App\Models\StudentFieldSection;
use App\Models\StudentFieldValue;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class DynamicTableColumnsTest extends TestCase
{
    use RefreshDatabase;

    public function test_table_renders_show_in_table_custom_columns(): void
    {
        $this->seed();
        $admin = User::where('email', 'sumit@davya.local')->first();
        $admin->must_change_password = false; $admin->save();
        $section = StudentFieldSection::firstOrCreate(['name' => 'Engagement'], ['position' => 99]);
        StudentField::create(['section_id' => $section->id, 'key' => 'demo_attended', 'label' => 'Demo Attended', 'type' => 'checkbox', 'is_required' => false, 'is_built_in' => false, 'show_in_table' => true, 'position' => 0]);
        StudentField::create(['section_id' => $section->id, 'key' => 'lead_source_extra', 'label' => 'Lead Source Extra', 'type' => 'dropdown', 'is_required' => false, 'is_built_in' => false, 'show_in_table' => true, 'options' => [['value' => 'ig', 'label' => 'Instagram']], 'position' => 1]);

        $student = Student::factory()->create(['name' => 'TableTestStudent', 'preference_r1' => 'BCA']);
        StudentFieldValue::create(['student_id' => $student->id, 'student_field_id' => StudentField::where('key', 'lead_source_extra')->value('id'), 'value_text' => 'ig']);

        Livewire::actingAs($admin)
            ->test(ListStudents::class)
            ->assertSee('Demo Attended')
            ->assertSee('Lead Source Extra')
            ->assertSee('Instagram');
    }
}
