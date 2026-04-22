<?php

namespace Tests\Feature;

use App\Filament\Pages\TodayPage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class TodayPageTest extends TestCase
{
    use RefreshDatabase;

    private function unblock(User $u): User
    {
        $u->must_change_password = false;
        $u->save();
        return $u;
    }

    public function test_admin_can_access_today_page(): void
    {
        $this->seed();
        $sumit = $this->unblock(User::where('email', 'sumit@davya.local')->firstOrFail());
        $this->actingAs($sumit);

        Livewire::test(TodayPage::class)->assertStatus(200);
        $this->assertTrue(TodayPage::canAccess());
    }

    public function test_head_can_access(): void
    {
        $this->seed();
        $nikhil = $this->unblock(User::where('email', 'nikhil@davya.local')->firstOrFail());
        $this->actingAs($nikhil);

        $this->assertTrue(TodayPage::canAccess());
    }

    public function test_member_can_access(): void
    {
        $this->seed();
        $nisha = $this->unblock(User::where('email', 'nisha@davya.local')->firstOrFail());
        $this->actingAs($nisha);

        $this->assertTrue(TodayPage::canAccess());
    }
}
