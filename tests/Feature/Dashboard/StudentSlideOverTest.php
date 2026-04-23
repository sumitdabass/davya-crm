<?php

namespace Tests\Feature\Dashboard;

use App\Livewire\StudentSlideOver;
use App\Models\Stage;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class StudentSlideOverTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function admin(): User
    {
        $u = User::where('email', 'sumit@davya.local')->first();
        $u->must_change_password = false; $u->save();
        return $u;
    }

    public function test_opens_with_correct_title_and_rows_for_stage_card(): void
    {
        $admin = $this->admin();
        $stage = Stage::first();

        Student::create([
            'phone' => '9444000001', 'name' => 'Row One', 'owner_id' => $admin->id,
            'lead_source' => 'Website', 'stage' => $stage->name,
            'stage_id' => $stage->id,
        ]);

        Livewire::actingAs($admin)
            ->test(StudentSlideOver::class)
            ->dispatch('open-slide-over', cardId: 'stage.'.$stage->id)
            ->assertSet('isOpen', true)
            ->assertSet('cardId', 'stage.'.$stage->id)
            ->assertSee('Row One')
            ->assertSee($stage->name);
    }

    public function test_slide_over_closed_by_default(): void
    {
        $admin = $this->admin();
        Livewire::actingAs($admin)
            ->test(StudentSlideOver::class)
            ->assertSet('isOpen', false);
    }

    public function test_unknown_card_id_is_noop(): void
    {
        $admin = $this->admin();
        Livewire::actingAs($admin)
            ->test(StudentSlideOver::class)
            ->dispatch('open-slide-over', cardId: 'nonexistent')
            ->assertSet('isOpen', false);
    }
}
