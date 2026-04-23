<?php
namespace Tests\Feature\StudentFields;

use App\Filament\Pages\StudentFieldsConfigPage;
use App\Models\Student;
use App\Models\StudentField;
use App\Models\StudentFieldSection;
use App\Models\StudentFieldValue;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SoftArchiveAndRestoreTest extends TestCase
{
    use RefreshDatabase;

    private function setupAdmin(): User
    {
        $this->seed();
        return User::where('email', 'sumit@davya.local')->first();
    }

    private function customField(): StudentField
    {
        $section = StudentFieldSection::firstOrCreate(['name' => 'Demographics'], ['position' => 99]);
        return StudentField::create(['section_id' => $section->id, 'key' => 'dob', 'label' => 'DOB', 'type' => 'date', 'is_required' => false, 'is_built_in' => false, 'position' => 0]);
    }

    public function test_archiving_field_preserves_values(): void
    {
        $admin = $this->setupAdmin();
        $field = $this->customField();
        $student = Student::factory()->create();
        StudentFieldValue::create(['student_id' => $student->id, 'student_field_id' => $field->id, 'value_date' => '2009-01-01']);

        Livewire::actingAs($admin)
            ->test(StudentFieldsConfigPage::class)
            ->call('archiveField', $field->id)
            ->assertHasNoErrors();

        $this->assertNotNull($field->fresh()->archived_at);
        $this->assertDatabaseHas('student_field_values', ['student_id' => $student->id, 'student_field_id' => $field->id]);
    }

    public function test_archived_field_restored_to_original_section(): void
    {
        $admin = $this->setupAdmin();
        $field = $this->customField();
        $field->update(['archived_at' => now()]);

        Livewire::actingAs($admin)
            ->test(StudentFieldsConfigPage::class)
            ->call('restoreField', $field->id)
            ->assertHasNoErrors();

        $this->assertNull($field->fresh()->archived_at);
    }

    public function test_hard_purge_with_typed_confirmation_wipes_values(): void
    {
        $admin = $this->setupAdmin();
        $field = $this->customField();
        $student = Student::factory()->create();
        StudentFieldValue::create(['student_id' => $student->id, 'student_field_id' => $field->id, 'value_date' => '2009-01-01']);
        $field->update(['archived_at' => now()]);

        Livewire::actingAs($admin)
            ->test(StudentFieldsConfigPage::class)
            ->call('hardDeleteField', $field->id, 'DELETE')
            ->assertHasNoErrors();

        $this->assertDatabaseMissing('student_fields', ['id' => $field->id]);
        $this->assertDatabaseMissing('student_field_values', ['student_field_id' => $field->id]);
    }

    public function test_hard_purge_blocked_without_correct_typed_confirmation(): void
    {
        $admin = $this->setupAdmin();
        $field = $this->customField();
        $field->update(['archived_at' => now()]);

        Livewire::actingAs($admin)
            ->test(StudentFieldsConfigPage::class)
            ->call('hardDeleteField', $field->id, 'oops')
            ->assertHasErrors(['confirm']);

        $this->assertDatabaseHas('student_fields', ['id' => $field->id]);
    }

    public function test_hard_purge_blocked_for_built_in(): void
    {
        $admin = $this->setupAdmin();
        $name = StudentField::where('key', 'name')->first();
        Livewire::actingAs($admin)
            ->test(StudentFieldsConfigPage::class)
            ->call('hardDeleteField', $name->id, 'DELETE')
            ->assertHasErrors(['archive']);
        $this->assertDatabaseHas('student_fields', ['id' => $name->id]);
    }
}
