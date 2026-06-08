<?php

namespace Tests\Feature;

use App\Filament\Resources\StudentResource\Pages\EditStudent;
use App\Filament\Resources\StudentResource\RelationManagers\PayoutsRelationManager;
use App\Models\Payout;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class PayoutsRelationManagerTest extends TestCase
{
    use RefreshDatabase;

    public function test_payouts_panel_lists_student_payouts(): void
    {
        $this->seed();
        $sumit = User::where('email', 'sumit@davya.local')->firstOrFail();
        $student = Student::create([
            'phone' => '9100000088', 'name' => 'PayoutPanel',
            'owner_id' => $sumit->id, 'referrer_id' => $sumit->id, 'lead_source' => 'Sumit',
        ]);
        $payout = Payout::factory()->create([
            'student_id' => $student->id, 'amount' => 40000, 'payee_type' => 'college',
            'recorded_by_user_id' => $sumit->id,
        ]);
        $this->actingAs($sumit);

        Livewire::test(PayoutsRelationManager::class, [
            'ownerRecord' => $student,
            'pageClass' => EditStudent::class,
        ])
            ->assertSuccessful()
            ->assertCanSeeTableRecords([$payout]);
    }
}
