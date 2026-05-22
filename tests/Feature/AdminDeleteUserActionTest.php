<?php

namespace Tests\Feature;

use App\Filament\Resources\UserResource\Pages\ListUsers;
use App\Models\Payment;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AdminDeleteUserActionTest extends TestCase
{
    use RefreshDatabase;

    private User $sumit;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->sumit = User::where('email', 'sumit@davya.local')->first();
        $this->sumit->must_change_password = false;
        $this->sumit->save();

        // delete_user action is gated on isSuperAdmin() (2026-05-02 sprint
        // moved every Filament DeleteAction to super_admin-only). Seed
        // sumit@davya.local with the role so these tests stay focused on
        // the reassign-then-delete logic rather than role plumbing.
        \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
        $this->sumit->assignRole('super_admin');
    }

    public function test_admin_can_delete_user_with_no_owned_records_without_reassignment(): void
    {
        $target = User::factory()->create(['is_active' => true]);
        $this->assertDatabaseHas('users', ['id' => $target->id]);

        $this->actingAs($this->sumit);

        Livewire::test(ListUsers::class)
            ->callTableAction('delete_user', $target)
            ->assertHasNoTableActionErrors();

        $this->assertDatabaseMissing('users', ['id' => $target->id]);
    }

    public function test_admin_delete_user_with_owned_records_reassigns_then_deletes(): void
    {
        $replacement = User::factory()->create(['is_active' => true]);
        $target = User::factory()->create(['is_active' => true]);

        $student = Student::factory()->create([
            'owner_id' => $target->id,
            'referrer_id' => $target->id,
        ]);
        $payment = Payment::factory()->create([
            'student_id' => $student->id,
            'recorded_by_user_id' => $target->id,
        ]);

        $this->actingAs($this->sumit);

        Livewire::test(ListUsers::class)
            ->callTableAction('delete_user', $target, data: ['reassign_to' => $replacement->id])
            ->assertHasNoTableActionErrors();

        $this->assertDatabaseMissing('users', ['id' => $target->id]);
        $this->assertDatabaseHas('students', [
            'id' => $student->id,
            'owner_id' => $replacement->id,
            'referrer_id' => $replacement->id,
        ]);
        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'recorded_by_user_id' => $replacement->id,
        ]);
    }

    public function test_admin_cannot_delete_themselves(): void
    {
        $this->actingAs($this->sumit);

        Livewire::test(ListUsers::class)
            ->assertTableActionHidden('delete_user', $this->sumit);
    }

    public function test_non_admin_cannot_reach_delete_action(): void
    {
        $nikhil = User::where('email', 'nikhil@davya.local')->first();
        $nikhil->must_change_password = false;
        $nikhil->save();

        $this->actingAs($nikhil);
        $this->get('/admin/users')->assertStatus(403);
    }
}
