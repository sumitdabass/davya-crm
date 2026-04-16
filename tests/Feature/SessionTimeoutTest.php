<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SessionTimeoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_is_logged_out_after_7_days(): void
    {
        $this->seed();
        $sumit = User::where('email', 'sumit@davya.local')->first();
        $this->actingAs($sumit);

        $this->withSession(['_login_at' => now()->subDays(8)->timestamp])
            ->get('/admin')
            ->assertRedirect('/admin/login');
    }

    public function test_login_at_timestamp_is_set_on_first_request_if_missing(): void
    {
        $this->seed();
        $sumit = User::where('email', 'sumit@davya.local')->first();
        $this->actingAs($sumit);

        $this->get('/admin');
        $this->assertNotNull(session('_login_at'));
        $this->assertLessThanOrEqual(now()->timestamp, session('_login_at'));
    }
}
