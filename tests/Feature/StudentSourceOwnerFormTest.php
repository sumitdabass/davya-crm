<?php

namespace Tests\Feature;

use App\Filament\Resources\StudentResource\Pages\CreateStudent;
use App\Filament\Resources\StudentResource\Pages\EditStudent;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class StudentSourceOwnerFormTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_options_only_include_admins_and_heads(): void
    {
        $this->seed();
        $sumit = User::where('email', 'sumit@davya.local')->first();
        $sumit->update(['must_change_password' => false]);
        $this->actingAs($sumit);

        Livewire::test(CreateStudent::class)
            ->assertFormFieldExists('owner_id');

        // Build the Select's options the same way the resource does.
        $ownerIds = User::query()
            ->whereHas('roles', fn ($q) => $q->whereIn('name', ['admin', 'head']))
            ->pluck('id');

        $this->assertTrue($ownerIds->contains($sumit->id));
        $nikhil = User::where('email', 'nikhil@davya.local')->first();
        $sonam = User::where('email', 'sonam@davya.local')->first();
        $nisha = User::where('email', 'nisha@davya.local')->first();
        $this->assertTrue($ownerIds->contains($nikhil->id));
        $this->assertTrue($ownerIds->contains($sonam->id));
        $this->assertFalse($ownerIds->contains($nisha->id), 'members must not appear as owners');
    }

    public function test_referrer_id_is_required_post_2026_05_02_refactor(): void
    {
        // Replaces the old test_referrer_name_saves_as_plain_text — that test
        // predates the 2026-05-02 sprint which removed the free-text
        // referrer_name TextInput from the admin form and replaced it with a
        // required referrer_id Select (Lead Owner). The referrer_name DB column
        // still exists but it's no longer driven by the manual form.
        $this->seed();
        $sumit = User::where('email', 'sumit@davya.local')->first();
        $sumit->update(['must_change_password' => false]);
        $this->actingAs($sumit);

        Livewire::test(CreateStudent::class)
            ->fillForm([
                'phone' => '9999900100',
                'name' => 'Test',
                'owner_id' => $sumit->id,
                // referrer_id intentionally omitted — should fail validation.
                'lead_source' => 'Sumit',
                'stage' => 'Lead Captured',
                'preference_r1' => 'IPEM',
            ])
            ->call('create')
            ->assertHasFormErrors(['referrer_id']);
    }
}
