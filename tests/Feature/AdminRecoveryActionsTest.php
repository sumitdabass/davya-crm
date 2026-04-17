<?php

namespace Tests\Feature;

use App\Filament\Resources\UserResource\Pages\ListUsers;
use App\Models\User;
use App\Services\TwoFactorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

class AdminRecoveryActionsTest extends TestCase
{
    use RefreshDatabase;

    private User $sumit;
    private User $nisha;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->sumit = User::where('email', 'sumit@davya.local')->first();
        $this->sumit->must_change_password = false;
        $this->sumit->save();

        $this->nisha = User::where('email', 'nisha@davya.local')->first();
    }

    public function test_admin_can_reset_2fa_for_a_user_with_2fa_enabled(): void
    {
        $this->nisha->totp_secret = (new TwoFactorService)->generateSecret();
        $this->nisha->totp_confirmed_at = now();
        $this->nisha->totp_recovery_codes = json_encode(['CODE-1','CODE-2']);
        $this->nisha->save();
        $this->assertTrue($this->nisha->fresh()->hasTwoFactorEnabled());

        $this->actingAs($this->sumit);

        Livewire::test(ListUsers::class)
            ->callTableAction('reset_2fa', $this->nisha)
            ->assertHasNoTableActionErrors();

        $fresh = $this->nisha->fresh();
        $this->assertNull($fresh->totp_secret);
        $this->assertNull($fresh->totp_confirmed_at);
        $this->assertNull($fresh->totp_recovery_codes);
        $this->assertFalse($fresh->hasTwoFactorEnabled());
    }

    public function test_admin_set_temp_password_replaces_hash_and_sets_must_change_flag(): void
    {
        $originalHash = $this->nisha->password;
        $this->nisha->must_change_password = false;
        $this->nisha->save();

        $this->actingAs($this->sumit);

        Livewire::test(ListUsers::class)
            ->callTableAction('set_temp_password', $this->nisha)
            ->assertHasNoTableActionErrors();

        $fresh = $this->nisha->fresh();
        $this->assertNotSame($originalHash, $fresh->password, 'Password hash must change');
        $this->assertTrue($fresh->must_change_password, 'must_change_password must be set back to true');
    }

    public function test_non_admin_cannot_see_the_recovery_actions(): void
    {
        // Head-only user (Nikhil) cannot even view /admin/users (canViewAny = admin only),
        // so the actions aren't even reachable.
        $nikhil = User::where('email', 'nikhil@davya.local')->first();
        $nikhil->must_change_password = false;
        $nikhil->save();
        $this->actingAs($nikhil);

        $this->get('/admin/users')->assertStatus(403);
    }
}
