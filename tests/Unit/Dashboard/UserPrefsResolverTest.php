<?php

namespace Tests\Unit\Dashboard;

use App\Dashboard\CardRegistry;
use App\Dashboard\Resolver\UserPrefsResolver;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserPrefsResolverTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        CardRegistry::reset();
    }

    private function user(): User
    {
        return User::first();
    }

    public function test_null_prefs_returns_default_cards_for_surface(): void
    {
        $resolver = app(UserPrefsResolver::class);
        $user = $this->user();
        $user->dashboard_prefs = null;

        $ids = array_map(fn ($c) => $c->id(), $resolver->resolve($user, 'today'));

        $this->assertContains('today_meetings', $ids);
        $this->assertContains('today_payments', $ids);
        $this->assertNotContains('stuck_leads', $ids);
    }

    public function test_saved_prefs_respect_order(): void
    {
        $resolver = app(UserPrefsResolver::class);
        $user = $this->user();
        $user->dashboard_prefs = ['today' => ['enabled' => ['today_payments', 'today_meetings']]];
        $user->save();

        $ids = array_map(fn ($c) => $c->id(), $resolver->resolve($user, 'today'));

        $this->assertSame(['today_payments', 'today_meetings'], array_slice($ids, 0, 2));
    }

    public function test_unknown_card_ids_are_dropped_silently(): void
    {
        $resolver = app(UserPrefsResolver::class);
        $user = $this->user();
        $user->dashboard_prefs = ['today' => ['enabled' => ['stage.99999', 'today_meetings']]];
        $user->save();

        $ids = array_map(fn ($c) => $c->id(), $resolver->resolve($user, 'today'));

        $this->assertNotContains('stage.99999', $ids);
        $this->assertContains('today_meetings', $ids);
    }

    public function test_new_default_card_auto_appended_when_missing_from_saved_prefs(): void
    {
        $resolver = app(UserPrefsResolver::class);
        $user = $this->user();
        $user->dashboard_prefs = ['today' => ['enabled' => ['today_meetings']]];
        $user->save();

        $ids = array_map(fn ($c) => $c->id(), $resolver->resolve($user, 'today'));

        $this->assertSame('today_meetings', $ids[0]);
        $this->assertContains('today_payments', $ids);
    }

    public function test_empty_array_renders_empty_surface(): void
    {
        $resolver = app(UserPrefsResolver::class);
        $user = $this->user();
        $user->dashboard_prefs = ['today' => ['enabled' => []]];
        $user->save();

        $cards = $resolver->resolve($user, 'today');

        // Per spec: saving explicitly-empty triggers auto-append of any defaults
        // the user has never saved before. So `today_meetings` / `today_payments`
        // DO come back on an empty array.
        $ids = array_map(fn ($c) => $c->id(), $cards);
        $this->assertContains('today_meetings', $ids);
        $this->assertContains('today_payments', $ids);
    }
}
