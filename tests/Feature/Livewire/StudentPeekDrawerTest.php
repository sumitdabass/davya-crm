<?php

namespace Tests\Feature\Livewire;

use App\Livewire\StudentPeekDrawer;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class StudentPeekDrawerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function unblock(User $user): User
    {
        $user->must_change_password = false;
        $user->save();
        return $user;
    }

    private function admin(): User
    {
        return $this->unblock(User::where('email', 'sumit@davya.local')->first());
    }

    public function test_opens_with_student_id(): void
    {
        $admin = $this->admin();
        $student = Student::create(['name' => 'Chaitanya', 'phone' => '9999900001', 'lead_source' => 'Direct', 'owner_id' => $admin->id]);

        Livewire::actingAs($admin)
            ->test(StudentPeekDrawer::class)
            ->dispatch('open-student-peek', studentId: $student->id)
            ->assertSet('isOpen', true)
            ->assertSet('studentId', $student->id)
            ->assertSee('Chaitanya');
    }

    public function test_close_resets_state(): void
    {
        $admin = $this->admin();
        $student = Student::create(['name' => 'X', 'phone' => '9999900010', 'lead_source' => 'Direct', 'owner_id' => $admin->id]);

        Livewire::actingAs($admin)
            ->test(StudentPeekDrawer::class)
            ->dispatch('open-student-peek', studentId: $student->id)
            ->call('close')
            ->assertSet('isOpen', false)
            ->assertSet('studentId', null);
    }

    public function test_swap_in_updates_student_without_closing(): void
    {
        $admin = $this->admin();
        $a = Student::create(['name' => 'Alpha', 'phone' => '9999900020', 'lead_source' => 'Direct', 'owner_id' => $admin->id]);
        $b = Student::create(['name' => 'Bravo', 'phone' => '9999900021', 'lead_source' => 'Direct', 'owner_id' => $admin->id]);

        Livewire::actingAs($admin)
            ->test(StudentPeekDrawer::class)
            ->dispatch('open-student-peek', studentId: $a->id)
            ->dispatch('open-student-peek', studentId: $b->id)
            ->assertSet('isOpen', true)
            ->assertSet('studentId', $b->id)
            ->assertSee('Bravo')
            ->assertDontSee('Alpha');
    }

    public function test_visible_to_scope_prevents_cross_team_peek(): void
    {
        Role::firstOrCreate(['name' => 'head', 'guard_name' => 'web']);

        $other = User::factory()->create();
        $other->assignRole('head');
        $this->unblock($other);
        $theirs = Student::create(['name' => 'Theirs', 'phone' => '9999900030', 'lead_source' => 'Direct', 'owner_id' => $other->id]);

        $me = User::factory()->create();
        $me->assignRole('head');
        $this->unblock($me);

        Livewire::actingAs($me)
            ->test(StudentPeekDrawer::class)
            ->dispatch('open-student-peek', studentId: $theirs->id)
            ->assertSet('isOpen', false);
    }

    public function test_switch_tab(): void
    {
        $admin = $this->admin();
        $student = Student::create(['name' => 'Y', 'phone' => '9999900040', 'lead_source' => 'Direct', 'owner_id' => $admin->id]);

        Livewire::actingAs($admin)
            ->test(StudentPeekDrawer::class)
            ->dispatch('open-student-peek', studentId: $student->id)
            ->call('switchTab', 'payments')
            ->assertSet('activeTab', 'payments')
            ->call('switchTab', 'not-a-real-tab')
            ->assertSet('activeTab', 'overview');   // invalid falls back to overview
    }
}
