<?php

namespace Tests\Feature;

use App\Filament\Resources\ExpenseResource\Pages\ListExpenses;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class FinanceRoleTest extends TestCase
{
    use RefreshDatabase;

    private function unblock(User $u): User
    {
        $u->must_change_password = false;
        $u->save();
        return $u;
    }

    public function test_admin_can_access_expense_list(): void
    {
        $this->seed();
        $sumit = $this->unblock(User::where('email', 'sumit@davya.local')->firstOrFail());
        $this->actingAs($sumit);
        Livewire::test(ListExpenses::class)->assertStatus(200);
    }

    public function test_finance_role_user_can_access_expense_list(): void
    {
        $this->seed();
        $this->artisan('db:seed', ['--class' => 'FinanceRoleSeeder', '--force' => true]);

        $finUser = User::create([
            'name' => 'Finance User',
            'email' => 'finance@davya.local',
            'password' => bcrypt('x'),
            'is_active' => true,
            'must_change_password' => false,
        ]);
        $finUser->assignRole('finance');

        $this->actingAs($finUser);
        Livewire::test(ListExpenses::class)->assertStatus(200);
    }

    public function test_head_cannot_access_expense_list(): void
    {
        $this->seed();
        $nikhil = $this->unblock(User::where('email', 'nikhil@davya.local')->firstOrFail());
        $this->actingAs($nikhil);
        $this->assertFalse(\App\Filament\Resources\ExpenseResource::canViewAny());
    }

    public function test_member_cannot_access_expense_list(): void
    {
        $this->seed();
        $nisha = $this->unblock(User::where('email', 'nisha@davya.local')->firstOrFail());
        $this->actingAs($nisha);
        $this->assertFalse(\App\Filament\Resources\ExpenseResource::canViewAny());
    }
}
