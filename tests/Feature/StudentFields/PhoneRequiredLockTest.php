<?php
namespace Tests\Feature\StudentFields;

use App\Filament\Pages\StudentFieldsConfigPage;
use App\Models\StudentField;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class PhoneRequiredLockTest extends TestCase
{
    use RefreshDatabase;

    public function test_phone_required_cannot_be_unset_via_update(): void
    {
        $this->seed();
        $admin = User::where('email', 'sumit@davya.local')->first();
        $phone = StudentField::where('key', 'phone')->first();

        Livewire::actingAs($admin)
            ->test(StudentFieldsConfigPage::class)
            ->call('updateField', $phone->id, ['is_required' => false]);

        $this->assertTrue((bool) $phone->fresh()->is_required, 'phone is_required must remain true regardless of toggle');
    }
}
