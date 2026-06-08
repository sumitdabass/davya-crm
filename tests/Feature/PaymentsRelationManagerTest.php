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

    public function test_payments_panel_has_no_create_chooser_button(): void
    {
        $this->seed();
        $sumit = User::where('email', 'sumit@davya.local')->firstOrFail();
        $student = Student::create([
            'phone' => '9100000300', 'name' => 'NoChooser',
            'owner_id' => $sumit->id, 'referrer_id' => $sumit->id, 'lead_source' => 'Sumit',
        ]);
        $this->actingAs($sumit);

        Livewire::test(PaymentsRelationManager::class, [
            'ownerRecord' => $student,
            'pageClass' => EditStudent::class,
        ])
            ->assertSuccessful()
            ->assertTableActionDoesNotExist('newPaymentPayout');
    }
}
