<?php

namespace Tests\Feature\Dashboard;

use App\Livewire\CustomizeCardsModal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CustomizeCardsModalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function admin(): User
    {
        $u = User::where('email', 'sumit@davya.local')->first();
        $u->must_change_password = false; $u->save();
        return $u;
    }

    public function test_opens_with_current_enabled_and_available_cards_for_surface(): void
    {
        $admin = $this->admin();

        Livewire::actingAs($admin)
            ->test(CustomizeCardsModal::class)
            ->dispatch('open-customize-modal', surface: 'today')
            ->assertSet('isOpen', true)
            ->assertSet('surface', 'today')
            ->assertSee('Today Meetings')
            ->assertSee('Meetings Held Today')
            ->assertSee('Stuck Leads'); // available but not enabled by default on Today
    }

    public function test_toggle_moves_card_between_enabled_and_disabled(): void
    {
        $admin = $this->admin();

        $cmp = Livewire::actingAs($admin)
            ->test(CustomizeCardsModal::class)
            ->dispatch('open-customize-modal', surface: 'today');

        $initial = $cmp->get('enabled');
        $this->assertContains('today_meetings', $initial);

        $cmp->call('toggle', 'today_meetings');

        $afterRemove = $cmp->get('enabled');
        $this->assertNotContains('today_meetings', $afterRemove);

        $cmp->call('toggle', 'today_meetings');
        $afterReadd = $cmp->get('enabled');
        $this->assertContains('today_meetings', $afterReadd);
    }
}
