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

    public function test_save_writes_expected_json_shape_to_user(): void
    {
        $admin = $this->admin();

        Livewire::actingAs($admin)
            ->test(CustomizeCardsModal::class)
            ->dispatch('open-customize-modal', surface: 'today')
            ->call('toggle', 'today_payments')   // remove default
            ->call('save')
            ->assertSet('isOpen', false);

        $admin->refresh();
        $this->assertIsArray($admin->dashboard_prefs);
        $this->assertArrayHasKey('today', $admin->dashboard_prefs);
        $this->assertNotContains('today_payments', $admin->dashboard_prefs['today']['enabled']);
        $this->assertContains('today_meetings', $admin->dashboard_prefs['today']['enabled']);
    }

    public function test_reset_to_defaults_nulls_surface_key(): void
    {
        $admin = $this->admin();
        $admin->dashboard_prefs = ['today' => ['enabled' => ['today_meetings']]];
        $admin->save();

        Livewire::actingAs($admin)
            ->test(CustomizeCardsModal::class)
            ->dispatch('open-customize-modal', surface: 'today')
            ->call('resetToDefaults');

        $admin->refresh();
        // surface key removed; if no other surface keys, whole prefs → null.
        $this->assertTrue(
            $admin->dashboard_prefs === null || !isset($admin->dashboard_prefs['today']),
            'Expected dashboard_prefs to be null or not have today key after reset'
        );
    }
}
