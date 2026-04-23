<?php
namespace Tests\Feature\StudentFields;

use App\Filament\Pages\StudentFieldsConfigPage;
use App\Models\StudentField;
use App\Models\StudentFieldSection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SectionTransferOnDeleteTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $this->seed();
        return User::where('email', 'sumit@davya.local')->first();
    }

    public function test_admin_can_create_section(): void
    {
        Livewire::actingAs($this->admin())
            ->test(StudentFieldsConfigPage::class)
            ->call('createSection', 'Engagement')
            ->assertHasNoErrors();
        $this->assertDatabaseHas('student_field_sections', ['name' => 'Engagement']);
    }

    public function test_admin_can_rename_section(): void
    {
        $section = StudentFieldSection::create(['name' => 'Old', 'position' => 99]);
        Livewire::actingAs($this->admin())
            ->test(StudentFieldsConfigPage::class)
            ->call('renameSection', $section->id, 'New Name')
            ->assertHasNoErrors();
        $this->assertSame('New Name', $section->fresh()->name);
    }

    public function test_admin_can_reorder_sections(): void
    {
        $a = StudentFieldSection::create(['name' => 'A', 'position' => 0]);
        $b = StudentFieldSection::create(['name' => 'B', 'position' => 1]);
        Livewire::actingAs($this->admin())
            ->test(StudentFieldsConfigPage::class)
            ->call('reorderSections', [$b->id, $a->id])
            ->assertHasNoErrors();
        $this->assertSame(0, $b->fresh()->position);
        $this->assertSame(1, $a->fresh()->position);
    }

    public function test_deleting_empty_section_succeeds_directly(): void
    {
        $section = StudentFieldSection::create(['name' => 'Empty', 'position' => 99]);
        Livewire::actingAs($this->admin())
            ->test(StudentFieldsConfigPage::class)
            ->call('deleteSection', $section->id)
            ->assertHasNoErrors();
        $this->assertDatabaseMissing('student_field_sections', ['id' => $section->id]);
    }

    public function test_deleting_non_empty_section_requires_transfer(): void
    {
        $src = StudentFieldSection::create(['name' => 'Src', 'position' => 99]);
        $dst = StudentFieldSection::create(['name' => 'Dst', 'position' => 100]);
        $field = StudentField::create(['section_id' => $src->id, 'key' => 'tmp', 'label' => 'Tmp', 'type' => 'text', 'is_required' => false, 'is_built_in' => false, 'position' => 0]);

        // Delete without transfer target → blocked
        Livewire::actingAs($this->admin())
            ->test(StudentFieldsConfigPage::class)
            ->call('deleteSection', $src->id)
            ->assertHasErrors(['transfer_target']);
        $this->assertDatabaseHas('student_field_sections', ['id' => $src->id]);

        // Delete with transfer target → succeeds, field moved
        Livewire::actingAs($this->admin())
            ->test(StudentFieldsConfigPage::class)
            ->call('deleteSectionWithTransfer', $src->id, $dst->id)
            ->assertHasNoErrors();
        $this->assertDatabaseMissing('student_field_sections', ['id' => $src->id]);
        $this->assertSame($dst->id, $field->fresh()->section_id);
    }
}
