<?php

namespace Tests\Feature\MobileForm;

use App\Models\Student;
use App\Models\User;
use App\Filament\Resources\StudentResource\Pages\CreateStudent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ToggleButtonsPersistTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $this->seed();
        $u = User::where('email', 'sumit@davya.local')->first();
        $u->update(['must_change_password' => false]);
        $this->actingAs($u);

        return $u;
    }

    public function test_toggle_button_fields_persist_on_create(): void
    {
        $admin = $this->admin();

        Livewire::test(CreateStudent::class)
            ->fillForm([
                'owner_id' => $admin->id,
                'referrer_id' => $admin->id,
                'lead_source' => 'Google',
                'student_response' => 'Ready',
                'phone' => '9999900123',
                'name' => 'Toggle Test',
                'stage' => 'Lead Captured',
                'preference_r1' => 'MAIT',
                'category' => 'Delhi',
                'plan' => 'Counselling Online',
                'registration_status' => 'registration_done',
                'counselling_registration_status' => 'pending',
                'seat_allotment_fee_status' => 'not_allotted',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('students', [
            'name' => 'Toggle Test',
            'lead_source' => 'Google',
            'student_response' => 'Ready',
            'category' => 'Delhi',
            'plan' => 'Counselling Online',
            'registration_status' => 'registration_done',
            'counselling_registration_status' => 'pending',
            'seat_allotment_fee_status' => 'not_allotted',
        ]);
    }
}
