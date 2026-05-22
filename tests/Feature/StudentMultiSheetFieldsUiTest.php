<?php

namespace Tests\Feature;

use App\Filament\Resources\StudentResource\Pages\CreateStudent;
use App\Filament\Resources\StudentResource\Pages\EditStudent;
use App\Filament\Resources\StudentResource\Pages\ListStudents;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class StudentMultiSheetFieldsUiTest extends TestCase
{
    use RefreshDatabase;

    private User $sumit;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('drive');
        $this->seed();
        $this->sumit = User::where('email', 'sumit@davya.local')->firstOrFail();
        $this->sumit->must_change_password = false;
        $this->sumit->save();
        $this->actingAs($this->sumit);
    }

    public function test_create_form_persists_rank_state_email(): void
    {
        Livewire::test(CreateStudent::class)
            ->fillForm([
                'phone'         => '9100000777',
                'name'          => 'Multi Sheet Tester',
                'owner_id'      => $this->sumit->id,
                'referrer_id'   => $this->sumit->id,
                'lead_source'   => 'Sumit',
                'stage'         => 'Lead Captured',
                'preference_r1' => 'ABC College',
                'email'         => 'tester@example.com',
                'rank'          => '12345',
                'state'         => 'Haryana',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $student = Student::where('phone', '9100000777')->firstOrFail();
        $this->assertSame('tester@example.com', $student->email);
        $this->assertSame('12345', $student->rank);
        $this->assertSame('Haryana', $student->state);
    }

    public function test_create_form_requires_name_for_manual_entry(): void
    {
        // The Filament admin form requires name (TextInput->required()) so
        // manual entries always have a name. Null-name leads still come in via
        // the n8n /api/leads webhook bypassing this gate — that path is tested
        // elsewhere and is not what the admin form is responsible for.
        Livewire::test(CreateStudent::class)
            ->fillForm([
                'phone'         => '9100000778',
                'owner_id'      => $this->sumit->id,
                'referrer_id'   => $this->sumit->id,
                'lead_source'   => 'Sumit',
                'stage'         => 'Lead Captured',
                'preference_r1' => 'ABC College',
                // name intentionally omitted — should trigger required validation.
            ])
            ->call('create')
            ->assertHasFormErrors(['name']);

        $this->assertNull(Student::where('phone', '9100000778')->first(),
            'student must not be created when name is missing');
    }

    public function test_edit_form_loads_rank_state_email(): void
    {
        $student = Student::create([
            'phone'         => '9100000779',
            'name'          => 'Edit Me',
            'owner_id'      => $this->sumit->id,
            'referrer_id'   => $this->sumit->id,
            'lead_source'   => 'Sumit',
            'stage'         => 'Lead Captured',
            'preference_r1' => 'ABC College',
            'email'         => 'edit@example.com',
            'rank'          => '999',
            'state'         => 'Delhi',
        ]);

        Livewire::test(EditStudent::class, ['record' => $student->getRouteKey()])
            ->assertFormSet([
                'email' => 'edit@example.com',
                'rank'  => '999',
                'state' => 'Delhi',
            ]);
    }

    public function test_list_page_renders_with_new_columns_available(): void
    {
        Student::create([
            'phone'         => '9100000780',
            'name'          => 'In List',
            'owner_id'      => $this->sumit->id,
            'referrer_id'   => $this->sumit->id,
            'lead_source'   => 'Sumit',
            'stage'         => 'Lead Captured',
            'preference_r1' => 'ABC College',
            'email'         => 'list@example.com',
            'rank'          => '42',
            'state'         => 'Punjab',
        ]);

        Livewire::test(ListStudents::class)
            ->set('toggledTableColumns', [
                'email'      => true,
                'rank'       => true,
                'state'      => true,
                'updated_at' => true,
            ])
            ->assertStatus(200)
            ->assertCanRenderTableColumn('email')
            ->assertCanRenderTableColumn('rank')
            ->assertCanRenderTableColumn('state');
    }
}
