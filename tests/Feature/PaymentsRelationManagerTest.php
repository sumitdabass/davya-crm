<?php

namespace Tests\Feature;

use App\Filament\Resources\StudentResource\Pages\EditStudent;
use App\Filament\Resources\StudentResource\RelationManagers\PaymentsRelationManager;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class PaymentsRelationManagerTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_payment_defaults_recorded_by_user_id_to_current_user(): void
    {
        $this->seed();

        $sumit = User::where('email', 'sumit@davya.local')->firstOrFail();
        $student = Student::create([
            'phone' => '9100000099',
            'name' => 'TestStudent',
            'owner_id' => $sumit->id,
            'referrer_id' => $sumit->id,
            'lead_source' => 'Sumit',
        ]);

        $this->actingAs($sumit);

        Livewire::test(PaymentsRelationManager::class, [
            'ownerRecord' => $student,
            'pageClass' => EditStudent::class,
        ])
            ->callTableAction('create', data: [
                'type' => 'advance',
                'amount' => 10000,
                'mode' => 'cash',
                'received_at' => now()->toDateTimeString(),
            ])
            ->assertHasNoTableActionErrors();

        $this->assertEquals(1, $student->payments()->count());
        $this->assertEquals($sumit->id, $student->payments()->first()->recorded_by_user_id);
    }
}
