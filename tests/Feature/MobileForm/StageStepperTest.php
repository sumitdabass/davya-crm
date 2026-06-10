<?php

namespace Tests\Feature\MobileForm;

use App\Filament\Resources\StudentResource\Pages\EditStudent;
use App\Models\Student;
use App\Models\User;
use App\Services\Pipeline\PipelineConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class StageStepperTest extends TestCase
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

    public function test_stepper_renders_all_pipeline_stages_on_create(): void
    {
        $this->admin();
        $names = app(PipelineConfig::class)->stageNames();

        $component = Livewire::test(\App\Filament\Resources\StudentResource\Pages\CreateStudent::class);
        foreach ($names as $name) {
            $component->assertSee($name);
        }
    }

    public function test_setting_stage_updates_state_like_the_old_select(): void
    {
        $admin = $this->admin();
        $student = Student::factory()->create([
            'owner_id' => $admin->id,
            'referrer_id' => $admin->id,
            'stage' => 'Lead Captured',
        ]);

        // Equivalent to a stepper tap: $wire.set('data.stage', ...) on the live field.
        Livewire::test(EditStudent::class, ['record' => $student->getKey()])
            ->set('data.stage', 'Meeting Scheduled')
            ->assertSet('data.stage', 'Meeting Scheduled');
    }
}
