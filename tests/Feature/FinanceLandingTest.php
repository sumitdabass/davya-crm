<?php

namespace Tests\Feature;

use App\Filament\Pages\FinanceLanding;
use App\Finance\FinanceRegistry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class FinanceLandingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function unblock(User $u): User
    {
        $u->must_change_password = false;
        $u->save();
        return $u;
    }

    public function test_admin_sees_both_cards(): void
    {
        $admin = $this->unblock(User::where('email', 'sumit@davya.local')->firstOrFail());
        $this->actingAs($admin);

        $cards = FinanceRegistry::accessibleFor($admin);
        $this->assertCount(2, $cards);
        $this->assertSame(['expenses', 'investments'], array_map(fn ($c) => $c['key'], $cards));

        $this->assertTrue(FinanceLanding::canAccess());

        Livewire::actingAs($admin)
            ->test(FinanceLanding::class)
            ->assertSee('Expenses')
            ->assertSee('Investments');
    }

    public function test_finance_role_sees_both_cards(): void
    {
        Role::firstOrCreate(['name' => 'finance', 'guard_name' => 'web']);
        $u = User::factory()->create();
        $u->assignRole('finance');
        $this->unblock($u);
        $this->actingAs($u);

        $this->assertCount(2, FinanceRegistry::accessibleFor($u));
        $this->assertTrue(FinanceLanding::canAccess());
    }

    public function test_head_without_finance_role_cannot_access(): void
    {
        $sonam = $this->unblock(User::where('email', 'sonam@davya.local')->firstOrFail());
        $this->actingAs($sonam);

        $this->assertSame([], FinanceRegistry::accessibleFor($sonam));
        $this->assertFalse(FinanceLanding::canAccess());
    }

    public function test_member_cannot_access(): void
    {
        $nisha = $this->unblock(User::where('email', 'nisha@davya.local')->firstOrFail());
        $this->actingAs($nisha);

        $this->assertFalse(FinanceLanding::canAccess());
    }

    public function test_guest_cannot_access(): void
    {
        $this->assertFalse(FinanceLanding::canAccess());
        $this->assertSame([], FinanceRegistry::accessibleFor(null));
    }
}
