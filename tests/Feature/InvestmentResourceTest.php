<?php

namespace Tests\Feature;

use App\Filament\Resources\InvestmentResource\Pages\CreateInvestment;
use App\Filament\Resources\InvestmentResource\Pages\EditInvestment;
use App\Models\Investment;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class InvestmentResourceTest extends TestCase
{
    use RefreshDatabase;

    private function unblock(User $u): User
    {
        $u->must_change_password = false;
        $u->save();
        return $u;
    }

    private function actingAsAdmin(): void
    {
        $this->seed();
        $sumit = $this->unblock(User::where('email', 'sumit@davya.local')->firstOrFail());
        $this->actingAs($sumit);
    }

    public function test_manual_investment_renders_D_prefix(): void
    {
        $i = Investment::create([
            'asset_name' => 'Tata Motors',
            'amount' => 50000,
            'direction' => 'out',
            'transacted_at' => now(),
            'slack_message_id' => null,
        ]);
        $this->assertSame("D{$i->id}", $i->display_id);
    }

    public function test_slack_captured_investment_renders_hash_prefix(): void
    {
        $i = Investment::create([
            'asset_name' => 'Tata Motors',
            'amount' => 50000,
            'direction' => 'out',
            'transacted_at' => now(),
            'slack_message_id' => '1776582096.431769',
        ]);
        $this->assertSame("#{$i->id}", $i->display_id);
    }

    public function test_manual_create_via_form_leaves_slack_id_null(): void
    {
        $this->actingAsAdmin();

        Livewire::test(CreateInvestment::class)
            ->fillForm([
                'asset_name' => 'Reliance',
                'amount' => 75000,
                'direction' => 'in',
                'transacted_at' => now()->toDateTimeString(),
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $row = Investment::latest('id')->first();
        $this->assertNotNull($row);
        $this->assertNull($row->slack_message_id);
        $this->assertSame("D{$row->id}", $row->display_id);
        $this->assertSame('Reliance', $row->asset_name);
    }

    public function test_admin_can_update_investment(): void
    {
        $this->actingAsAdmin();
        $i = Investment::create([
            'asset_name' => 'Tata',
            'amount' => 1000,
            'direction' => 'out',
            'transacted_at' => now(),
            'slack_message_id' => null,
        ]);

        Livewire::test(EditInvestment::class, ['record' => $i->getRouteKey()])
            ->fillForm(['asset_name' => 'Tata Motors', 'amount' => 2000, 'direction' => 'in'])
            ->call('save')
            ->assertHasNoFormErrors();

        $i->refresh();
        $this->assertSame('Tata Motors', $i->asset_name);
        $this->assertEqualsWithDelta(2000.0, (float) $i->amount, 0.01);
        $this->assertSame('in', $i->direction);
    }

    public function test_admin_cannot_delete_investment(): void
    {
        // Policy intent (2026-05-02 sprint): finance deletes are super_admin-only.
        $this->actingAsAdmin();
        $i = Investment::create([
            'asset_name' => 'Admin attempt',
            'amount' => 1,
            'direction' => 'out',
            'transacted_at' => now(),
            'slack_message_id' => null,
        ]);

        $this->assertFalse(auth()->user()->can('delete', $i), 'policy must reject admin delete');
    }

    public function test_super_admin_can_delete_investment(): void
    {
        $this->seed();
        \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
        $sumit = $this->unblock(User::where('email', 'sumit@davya.local')->firstOrFail());
        $sumit->assignRole('super_admin');
        $this->actingAs($sumit);

        $i = Investment::create([
            'asset_name' => 'Garbage',
            'amount' => 1,
            'direction' => 'out',
            'transacted_at' => now(),
            'slack_message_id' => null,
        ]);

        $this->assertTrue(auth()->user()->can('delete', $i), 'policy must allow super_admin delete');
        $i->delete();
        $this->assertNull(Investment::find($i->id));
    }

    public function test_slack_message_id_unique_constraint_survives_migration(): void
    {
        Investment::create([
            'asset_name' => 'X', 'amount' => 1, 'direction' => 'in',
            'transacted_at' => now(), 'slack_message_id' => 'inv-dup-1',
        ]);
        $this->expectException(QueryException::class);
        Investment::create([
            'asset_name' => 'Y', 'amount' => 2, 'direction' => 'in',
            'transacted_at' => now(), 'slack_message_id' => 'inv-dup-1',
        ]);
    }

    public function test_two_manual_rows_can_coexist(): void
    {
        $a = Investment::create(['asset_name'=>'A','amount'=>1,'direction'=>'in','transacted_at'=>now(),'slack_message_id'=>null]);
        $b = Investment::create(['asset_name'=>'B','amount'=>2,'direction'=>'in','transacted_at'=>now(),'slack_message_id'=>null]);
        $this->assertNotSame($a->id, $b->id);
    }
}
