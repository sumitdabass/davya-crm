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

    public function test_dashboard_and_today_pages_listen_for_dashboard_prefs_saved(): void
    {
        // Bug 2 from prod smoke 2026-04-23: dashboard kept rendering defaults
        // after Save with empty enabled array because DashboardPage / TodayPage
        // didn't listen for the dashboard-prefs-saved Livewire event, so the
        // page never re-rendered. Guard the listener exists on both pages.
        foreach ([\App\Filament\Pages\DashboardPage::class, \App\Filament\Pages\TodayPage::class] as $pageClass) {
            $body = file_get_contents((new \ReflectionClass($pageClass))->getFileName());
            $this->assertStringContainsString("#[On('dashboard-prefs-saved')]", $body, "$pageClass missing #[On('dashboard-prefs-saved')] listener");
        }
    }

    public function test_save_empty_enabled_array_persists_as_empty_not_defaults(): void
    {
        // SP#3 follow-up (b): previously the resolver auto-appended defaults
        // for empty saved arrays, which silently undid "uncheck all". The
        // resolver now respects []; this test guards the end-to-end save path.
        $admin = $this->admin();

        Livewire::actingAs($admin)
            ->test(CustomizeCardsModal::class)
            ->dispatch('open-customize-modal', surface: 'today')
            ->set('enabled', [])
            ->call('save');

        $fresh = $admin->fresh();
        $this->assertSame([], $fresh->dashboard_prefs['today']['enabled']);
    }

    public function test_reset_cards_to_defaults_event_restores_defaults_for_surface(): void
    {
        $admin = $this->admin();
        $admin->dashboard_prefs = ['today' => ['enabled' => []]];
        $admin->save();

        Livewire::actingAs($admin)
            ->test(CustomizeCardsModal::class)
            ->dispatch('reset-cards-to-defaults', surface: 'today');

        $prefs = $admin->fresh()->dashboard_prefs ?? [];
        $this->assertArrayNotHasKey('today', $prefs, 'reset should wipe the surface key so defaults apply again');
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

    public function test_reorder_updates_enabled_array_order(): void
    {
        $admin = $this->admin();

        $cmp = Livewire::actingAs($admin)
            ->test(CustomizeCardsModal::class)
            ->dispatch('open-customize-modal', surface: 'today');

        $originalFirst = $cmp->get('enabled')[0];
        $reversed = array_reverse($cmp->get('enabled'));

        $cmp->call('reorder', $reversed);

        $this->assertSame($reversed, $cmp->get('enabled'));
        $this->assertNotSame($originalFirst, $cmp->get('enabled')[0]);
    }

    public function test_remove_card_event_persists_removal_and_emits_undo_data(): void
    {
        $admin = $this->admin();
        $admin->dashboard_prefs = ['today' => ['enabled' => ['today_meetings', 'today_payments']]];
        $admin->save();

        Livewire::actingAs($admin)
            ->test(CustomizeCardsModal::class)
            ->dispatch('remove-card', surface: 'today', cardId: 'today_payments')
            ->assertDispatched('card-removed', cardId: 'today_payments', surface: 'today');

        $admin->refresh();
        $this->assertNotContains('today_payments', $admin->dashboard_prefs['today']['enabled']);
    }

    public function test_undo_restores_removed_card_at_original_position(): void
    {
        $admin = $this->admin();
        $admin->dashboard_prefs = ['today' => ['enabled' => ['today_meetings', 'today_payments', 'meetings_held_today']]];
        $admin->save();

        $cmp = Livewire::actingAs($admin)
            ->test(CustomizeCardsModal::class)
            ->dispatch('remove-card', surface: 'today', cardId: 'today_payments');

        $cmp->call('undoRemove', surface: 'today', cardId: 'today_payments', position: 1);

        $admin->refresh();
        $this->assertSame(
            ['today_meetings', 'today_payments', 'meetings_held_today'],
            $admin->dashboard_prefs['today']['enabled'],
        );
    }
}
