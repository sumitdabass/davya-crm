<?php
namespace Tests\Feature\StudentFields;

use App\Filament\Pages\StudentFieldsConfigPage;
use App\Models\StudentField;
use App\Models\StudentFieldSection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class StudentFieldsConfigPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_page_is_accessible_to_admin(): void
    {
        $this->seed();
        $admin = User::where('email', 'sumit@davya.local')->first();
        $this->actingAs($admin);
        $this->assertTrue(StudentFieldsConfigPage::canAccess());
    }

    public function test_page_is_blocked_for_non_admin(): void
    {
        $this->seed();
        $user = User::factory()->create();
        Role::firstOrCreate(['name' => 'counsellor']);
        $user->assignRole('counsellor');
        $this->actingAs($user);
        $this->assertFalse(StudentFieldsConfigPage::canAccess());
    }

    public function test_page_renders_for_admin(): void
    {
        $this->seed();
        $admin = User::where('email', 'sumit@davya.local')->first();
        $admin->must_change_password = false;
        $admin->save();
        $this->actingAs($admin)->get('/admin/student-fields')->assertOk();
    }

    public function test_page_does_not_define_getRules_method(): void
    {
        // BasePage::getRules() exists; the gotcha is shadowing it on the subclass.
        // Check the declaring class is NOT our page (i.e. we did not override it).
        $reflection = new \ReflectionMethod(StudentFieldsConfigPage::class, 'getRules');
        $this->assertNotSame(
            StudentFieldsConfigPage::class,
            $reflection->getDeclaringClass()->getName(),
            'Defining getRules() shadows Filament BasePage::getRules() and triggers fatal LSP error (SP#1 gotcha).'
        );
    }

    public function test_admin_can_create_custom_text_field(): void
    {
        $section = StudentFieldSection::create(['name' => 'Demographics', 'position' => 99]);
        \Livewire\Livewire::actingAs($this->seedAdmin())
            ->test(StudentFieldsConfigPage::class)
            ->call('createField', [
                'section_id' => $section->id,
                'label' => 'Email',
                'type' => 'email',
                'is_required' => false,
                'options' => null,
                'show_in_table' => true,
                'show_in_kanban' => false,
                'show_in_import' => true,
            ])
            ->assertHasNoErrors();
        $this->assertDatabaseHas('student_fields', ['key' => 'email', 'type' => 'email', 'section_id' => $section->id, 'show_in_table' => true]);
    }

    public function test_create_dropdown_field_persists_options(): void
    {
        $section = StudentFieldSection::create(['name' => 'Academic', 'position' => 99]);
        \Livewire\Livewire::actingAs($this->seedAdmin())
            ->test(StudentFieldsConfigPage::class)
            ->call('createField', [
                'section_id' => $section->id,
                'label' => 'Board',
                'type' => 'dropdown',
                'is_required' => false,
                'options' => [['value' => 'cbse', 'label' => 'CBSE'], ['value' => 'icse', 'label' => 'ICSE']],
                'show_in_table' => false,
                'show_in_kanban' => false,
                'show_in_import' => false,
            ])
            ->assertHasNoErrors();
        $field = StudentField::where('key', 'board')->first();
        $this->assertCount(2, $field->options);
        $this->assertSame('CBSE', $field->options[0]['label']);
    }

    public function test_field_key_is_auto_generated_as_slug(): void
    {
        $section = StudentFieldSection::create(['name' => 'X', 'position' => 99]);
        \Livewire\Livewire::actingAs($this->seedAdmin())
            ->test(StudentFieldsConfigPage::class)
            ->call('createField', ['section_id' => $section->id, 'label' => 'Marks 12th %', 'type' => 'number', 'is_required' => false, 'options' => null, 'show_in_table' => false, 'show_in_kanban' => false, 'show_in_import' => false]);
        $this->assertDatabaseHas('student_fields', ['key' => 'marks_12th_percent']);
    }

    public function test_admin_can_update_field_label_and_required(): void
    {
        $section = StudentFieldSection::create(['name' => 'X', 'position' => 99]);
        $field = StudentField::create(['section_id' => $section->id, 'key' => 'foo', 'label' => 'Foo', 'type' => 'text', 'is_required' => false, 'is_built_in' => false, 'position' => 0]);
        \Livewire\Livewire::actingAs($this->seedAdmin())
            ->test(StudentFieldsConfigPage::class)
            ->call('updateField', $field->id, ['label' => 'Bar', 'is_required' => true, 'show_in_table' => true, 'show_in_kanban' => true, 'show_in_import' => true])
            ->assertHasNoErrors();
        $f = $field->fresh();
        $this->assertSame('Bar', $f->label);
        $this->assertTrue($f->is_required);
        $this->assertTrue($f->show_in_table);
        $this->assertTrue($f->show_in_kanban);
        $this->assertTrue($f->show_in_import);
        $this->assertSame('foo', $f->key, 'key must not change on update');
    }

    public function test_admin_can_reorder_fields_within_section(): void
    {
        $section = StudentFieldSection::create(['name' => 'X', 'position' => 99]);
        $a = StudentField::create(['section_id' => $section->id, 'key' => 'a', 'label' => 'A', 'type' => 'text', 'is_required' => false, 'is_built_in' => false, 'position' => 0]);
        $b = StudentField::create(['section_id' => $section->id, 'key' => 'b', 'label' => 'B', 'type' => 'text', 'is_required' => false, 'is_built_in' => false, 'position' => 1]);
        \Livewire\Livewire::actingAs($this->seedAdmin())
            ->test(StudentFieldsConfigPage::class)
            ->call('reorderFields', $section->id, [$b->id, $a->id])
            ->assertHasNoErrors();
        $this->assertSame(0, $b->fresh()->position);
        $this->assertSame(1, $a->fresh()->position);
    }

    private function seedAdmin(): User
    {
        $this->seed();
        return User::where('email', 'sumit@davya.local')->first();
    }
}
