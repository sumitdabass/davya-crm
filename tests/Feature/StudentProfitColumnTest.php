<?php

namespace Tests\Feature;

use App\Filament\Resources\StudentResource\Pages\ListStudents;
use App\Models\Payout;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class StudentProfitColumnTest extends TestCase
{
    use RefreshDatabase;

    public function test_list_sorts_by_expected_profit(): void
    {
        $this->seed();
        $admin = User::where('email', 'sumit@davya.local')->first();
        $admin->must_change_password = false;
        $admin->save();

        $this->actingAs($admin);

        $low = Student::factory()->create(['deal_amount' => 100000, 'owner_id' => $admin->id]);
        $high = Student::factory()->create(['deal_amount' => 100000, 'owner_id' => $admin->id]);
        Payout::factory()->create(['student_id' => $low->id, 'amount' => 90000, 'recorded_by_user_id' => $admin->id]);
        Payout::factory()->create(['student_id' => $high->id, 'amount' => 10000, 'recorded_by_user_id' => $admin->id]);

        Livewire::test(ListStudents::class)
            ->sortTable('expected_profit', 'desc')
            ->assertCanSeeTableRecords([$high, $low], inOrder: true);
    }
}
