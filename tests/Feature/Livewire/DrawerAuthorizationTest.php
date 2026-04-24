<?php

namespace Tests\Feature\Livewire;

use App\Livewire\Drawer\ActivityTab;
use App\Livewire\Drawer\MeetingsTab;
use App\Livewire\Drawer\NotesTab;
use App\Livewire\Drawer\OverviewTab;
use App\Livewire\Drawer\PaymentsTab;
use App\Livewire\StudentPeekDrawer;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DrawerAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Role::firstOrCreate(['name' => 'head', 'guard_name' => 'web']);
    }

    private function unblock(User $user): User
    {
        $user->must_change_password = false;
        $user->save();
        return $user;
    }

    private function fresh_head(): User
    {
        $u = User::factory()->create();
        $u->assignRole('head');
        return $this->unblock($u);
    }

    public function test_drawer_getstudent_property_respects_scope(): void
    {
        $other = $this->fresh_head();
        $theirs = Student::create(['name' => 'Theirs', 'phone' => '9999800001', 'lead_source' => 'Direct', 'owner_id' => $other->id]);

        $me = $this->fresh_head();

        $component = Livewire::actingAs($me)->test(StudentPeekDrawer::class);
        // Force the property directly (simulate tampered state).
        $component->set('studentId', $theirs->id);
        $component->set('isOpen', true);
        // getStudentProperty must return null because scopeVisibleTo rejects the cross-team student.
        $this->assertNull($component->get('student'));
    }

    public function test_each_drawer_tab_aborts_on_unauthorized_mount(): void
    {
        $other = $this->fresh_head();
        $theirs = Student::create(['name' => 'Theirs', 'phone' => '9999800002', 'lead_source' => 'Direct', 'owner_id' => $other->id]);

        $me = $this->fresh_head();

        // In Livewire v3 testing, abort(403) is handled by the framework and
        // returned as an HTTP response (not re-thrown). Use assertForbidden().
        foreach ([OverviewTab::class, PaymentsTab::class, NotesTab::class, MeetingsTab::class, ActivityTab::class] as $tab) {
            Livewire::actingAs($me)
                ->test($tab, ['studentId' => $theirs->id])
                ->assertForbidden();
        }
    }

    public function test_each_drawer_tab_mounts_normally_for_authorized_user(): void
    {
        $admin = $this->unblock(User::where('email', 'sumit@davya.local')->first());
        $student = Student::create(['name' => 'Mine', 'phone' => '9999800003', 'lead_source' => 'Direct', 'owner_id' => $admin->id]);

        foreach ([OverviewTab::class, PaymentsTab::class, NotesTab::class, MeetingsTab::class, ActivityTab::class] as $tab) {
            Livewire::actingAs($admin)
                ->test($tab, ['studentId' => $student->id])
                ->assertOk();
        }
    }
}
