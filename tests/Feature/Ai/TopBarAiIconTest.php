<?php
namespace Tests\Feature\Ai;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TopBarAiIconTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_admin_sees_ai_icon(): void
    {
        $u = User::where('email', 'sumit@davya.local')->firstOrFail();
        $u->must_change_password = false;
        $u->save();
        $u->assignRole('admin');

        $this->actingAs($u)
            ->get('/admin')
            ->assertOk()
            ->assertSee('data-ai-drawer-trigger', false);
    }

    public function test_user_without_permission_does_not_see_icon(): void
    {
        $u = User::factory()->create(['is_active' => true, 'must_change_password' => false]);
        $u->assignRole('freelancer');

        $this->actingAs($u)
            ->get('/admin')
            ->assertOk()
            ->assertDontSee('data-ai-drawer-trigger', false);
    }
}
