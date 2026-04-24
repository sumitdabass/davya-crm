<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VisualRefreshFlagTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function adminUser(): User
    {
        $user = User::where('email', 'sumit@davya.local')->firstOrFail();
        $user->must_change_password = false;
        $user->save();
        return $user;
    }

    public function test_tokens_css_not_loaded_when_flag_off(): void
    {
        config(['davyas.visual_v2' => false]);
        $admin = $this->adminUser();

        $response = $this->actingAs($admin)->get('/admin');

        $response->assertOk();
        $response->assertDontSee('davya-tokens', false);
        $response->assertDontSee('/css/tokens.css', false);
    }

    public function test_tokens_css_loaded_when_flag_on(): void
    {
        config(['davyas.visual_v2' => true]);
        $admin = $this->adminUser();

        $response = $this->actingAs($admin)->get('/admin');

        $response->assertOk();
        $response->assertSee('davya-tokens', false);
    }
}
