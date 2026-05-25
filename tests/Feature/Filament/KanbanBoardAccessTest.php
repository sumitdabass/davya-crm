<?php

namespace Tests\Feature\Filament;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KanbanBoardAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_admin_can_access_kanban(): void
    {
        $admin = User::where('email', 'sumit@davya.local')->first();
        // Seeded users have must_change_password=true which would 302 via
        // RequirePasswordChange middleware (orthogonal to the access gate
        // under test); clear it so we measure the page's own canAccess().
        $admin->forceFill(['must_change_password' => false])->save();
        $this->actingAs($admin)
            ->get('/admin/kanban')
            ->assertStatus(200);
    }

    public function test_head_can_access_kanban(): void
    {
        $head = User::factory()->create(['is_active' => true]);
        $head->assignRole('head');
        $this->actingAs($head)
            ->get('/admin/kanban')
            ->assertStatus(200);
    }

    public function test_counsellor_can_access_kanban(): void
    {
        $counsellor = User::factory()->create(['is_active' => true]);
        $counsellor->assignRole('member');
        $this->actingAs($counsellor)
            ->get('/admin/kanban')
            ->assertStatus(200);
    }

    public function test_freelancer_cannot_access_kanban(): void
    {
        $freelancer = User::factory()->create(['is_active' => true]);
        $freelancer->assignRole('freelancer');
        $this->actingAs($freelancer)
            ->get('/admin/kanban')
            ->assertForbidden();
    }

    public function test_unauthenticated_redirects_to_login(): void
    {
        $this->get('/admin/kanban')
            ->assertRedirect('/admin/login');
    }
}
