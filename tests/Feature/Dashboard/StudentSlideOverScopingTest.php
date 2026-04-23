<?php

namespace Tests\Feature\Dashboard;

use App\Livewire\StudentSlideOver;
use App\Models\Stage;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class StudentSlideOverScopingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_counsellor_cannot_see_other_teams_students_via_drill_down(): void
    {
        $stage = Stage::first();

        $sonam = User::where('email', 'sonam@davya.local')->first()
            ?? User::factory()->create(['email' => 'sonam@davya.local']);
        $nikhil = User::where('email', 'nikhil@davya.local')->first()
            ?? User::factory()->create(['email' => 'nikhil@davya.local']);

        foreach ([$sonam, $nikhil] as $u) {
            $u->must_change_password = false;
            $u->save();
        }

        // Sonam owns these; Nikhil should not see them via drill-down.
        Student::create([
            'phone' => '9666000001', 'name' => 'Sonam Lead',
            'owner_id' => $sonam->id, 'lead_source' => 'Website',
            'stage' => $stage->name, 'stage_id' => $stage->id,
        ]);
        Student::create([
            'phone' => '9666000002', 'name' => 'Nikhil Lead',
            'owner_id' => $nikhil->id, 'lead_source' => 'Website',
            'stage' => $stage->name, 'stage_id' => $stage->id,
        ]);

        Livewire::actingAs($nikhil)
            ->test(StudentSlideOver::class)
            ->dispatch('open-slide-over', cardId: 'stage.'.$stage->id)
            ->assertDontSee('Sonam Lead')
            ->assertSee('Nikhil Lead');
    }
}
