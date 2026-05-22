<?php

namespace Tests\Feature;

use App\Filament\Resources\ExpenseResource\Pages\CreateExpense;
use App\Filament\Resources\ExpenseResource\Pages\EditExpense;
use App\Filament\Resources\ExpenseResource\Pages\ListExpenses;
use App\Models\Expense;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ExpenseResourceTest extends TestCase
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

    public function test_manual_expense_renders_D_prefix(): void
    {
        $e = Expense::create([
            'amount' => 1000,
            'description' => 'Manual test',
            'paid_at' => now(),
            'slack_message_id' => null,
        ]);
        $this->assertSame("D{$e->id}", $e->display_id, 'manual rows must use D prefix');
    }

    public function test_slack_captured_expense_renders_hash_prefix(): void
    {
        $e = Expense::create([
            'amount' => 2500,
            'description' => 'Captured from Slack',
            'paid_at' => now(),
            'slack_message_id' => '1776767527.655079',
        ]);
        $this->assertSame("#{$e->id}", $e->display_id, 'slack rows must use # prefix');
    }

    public function test_manual_create_via_form_leaves_slack_id_null(): void
    {
        $this->actingAsAdmin();

        Livewire::test(CreateExpense::class)
            ->fillForm([
                'amount' => 500,
                'category' => 'Office',
                'description' => 'printer paper',
                'paid_at' => now()->toDateTimeString(),
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $row = Expense::latest('id')->first();
        $this->assertNotNull($row, 'expense row must be created');
        $this->assertNull($row->slack_message_id, 'manual creates must leave slack_message_id NULL');
        $this->assertSame("D{$row->id}", $row->display_id);
        $this->assertEqualsWithDelta(500.0, (float) $row->amount, 0.01);
    }

    public function test_admin_can_update_expense(): void
    {
        $this->actingAsAdmin();
        $e = Expense::create([
            'amount' => 1000,
            'category' => 'Old',
            'description' => 'before',
            'paid_at' => now(),
            'slack_message_id' => null,
        ]);

        Livewire::test(EditExpense::class, ['record' => $e->getRouteKey()])
            ->fillForm(['amount' => 1234, 'category' => 'New', 'description' => 'after'])
            ->call('save')
            ->assertHasNoFormErrors();

        $e->refresh();
        $this->assertEqualsWithDelta(1234.0, (float) $e->amount, 0.01);
        $this->assertSame('New', $e->category);
        $this->assertSame('after', $e->description);
    }

    public function test_admin_cannot_delete_expense(): void
    {
        // Policy intent (2026-05-02 sprint): finance deletes are super_admin-only.
        // Admin gets viewAny/view/create/update but NOT delete — preserves an
        // audit trail unless explicitly nuked by super_admin.
        $this->actingAsAdmin();
        $e = Expense::create([
            'amount' => 999,
            'description' => 'admin tries to delete',
            'paid_at' => now(),
            'slack_message_id' => null,
        ]);

        $this->assertFalse(auth()->user()->can('delete', $e), 'policy must reject admin delete');
    }

    public function test_super_admin_can_delete_expense(): void
    {
        $this->seed();
        \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
        $sumit = $this->unblock(User::where('email', 'sumit@davya.local')->firstOrFail());
        $sumit->assignRole('super_admin');
        $this->actingAs($sumit);

        $e = Expense::create([
            'amount' => 999,
            'description' => 'to be deleted',
            'paid_at' => now(),
            'slack_message_id' => null,
        ]);

        $this->assertTrue(auth()->user()->can('delete', $e), 'policy must allow super_admin delete');
        $e->delete();
        $this->assertNull(Expense::find($e->id), 'row must be gone');
    }

    public function test_slack_message_id_unique_constraint_survives_migration(): void
    {
        Expense::create([
            'amount' => 1, 'description' => 'a',
            'paid_at' => now(), 'slack_message_id' => 'dup-1',
        ]);
        $this->expectException(QueryException::class);
        Expense::create([
            'amount' => 2, 'description' => 'b',
            'paid_at' => now(), 'slack_message_id' => 'dup-1',
        ]);
    }

    public function test_two_manual_rows_can_coexist(): void
    {
        $a = Expense::create(['amount'=>1,'description'=>'a','paid_at'=>now(),'slack_message_id'=>null]);
        $b = Expense::create(['amount'=>2,'description'=>'b','paid_at'=>now(),'slack_message_id'=>null]);
        $this->assertNotSame($a->id, $b->id);
    }
}
