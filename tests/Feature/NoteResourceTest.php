<?php

namespace Tests\Feature;

use App\Filament\Resources\NoteResource;
use App\Filament\Resources\NoteResource\Pages\CreateNote;
use App\Filament\Resources\NoteResource\Pages\EditNote;
use App\Models\Note;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class NoteResourceTest extends TestCase
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

    public function test_manual_create_via_form_leaves_slack_id_null(): void
    {
        $this->actingAsAdmin();

        Livewire::test(CreateNote::class)
            ->fillForm(['body' => 'printer paper reminder'])
            ->call('create')
            ->assertHasNoFormErrors();

        $row = Note::latest('id')->first();
        $this->assertNotNull($row, 'note row must be created');
        $this->assertNull($row->slack_message_id, 'manual creates must leave slack_message_id NULL');
        $this->assertSame("D{$row->id}", $row->display_id);
        $this->assertSame('printer paper reminder', $row->body);
    }

    public function test_admin_can_update_note(): void
    {
        $this->actingAsAdmin();
        $n = Note::create(['body' => 'before', 'noted_at' => now(), 'slack_message_id' => null]);

        Livewire::test(EditNote::class, ['record' => $n->getRouteKey()])
            ->fillForm(['body' => 'after'])
            ->call('save')
            ->assertHasNoFormErrors();

        $n->refresh();
        $this->assertSame('after', $n->body);
    }

    public function test_can_view_any_gates_resource_at_route_level(): void
    {
        $this->seed();
        $this->assertFalse(NoteResource::canViewAny(), 'guest must not see NoteResource');

        $sumit = $this->unblock(User::where('email', 'sumit@davya.local')->firstOrFail());
        $this->actingAs($sumit);
        $this->assertTrue(NoteResource::canViewAny(), 'admin can see NoteResource');

        $sonam = $this->unblock(User::where('email', 'sonam@davya.local')->firstOrFail());
        $this->actingAs($sonam);
        $this->assertFalse(NoteResource::canViewAny(), 'head without finance role cannot see NoteResource');
    }

    public function test_admin_cannot_delete_note(): void
    {
        $this->actingAsAdmin();
        $n = Note::create(['body' => 'admin tries delete', 'noted_at' => now(), 'slack_message_id' => null]);
        $this->assertFalse(auth()->user()->can('delete', $n), 'policy must reject admin delete');
    }

    public function test_super_admin_can_delete_note(): void
    {
        $this->seed();
        \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
        $sumit = $this->unblock(User::where('email', 'sumit@davya.local')->firstOrFail());
        $sumit->assignRole('super_admin');
        $this->actingAs($sumit);

        $n = Note::create(['body' => 'to be deleted', 'noted_at' => now(), 'slack_message_id' => null]);
        $this->assertTrue(auth()->user()->can('delete', $n), 'policy must allow super_admin delete');
        $n->delete();
        $this->assertNull(Note::find($n->id), 'row must be gone');
    }

    public function test_slack_message_id_unique_constraint(): void
    {
        Note::create(['body' => 'a', 'noted_at' => now(), 'slack_message_id' => 'dup-1']);
        $this->expectException(QueryException::class);
        Note::create(['body' => 'b', 'noted_at' => now(), 'slack_message_id' => 'dup-1']);
    }
}
