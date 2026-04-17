<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SecurityHardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_security_headers_are_present_on_every_response(): void
    {
        $resp = $this->get('/');

        $resp->assertHeader('X-Frame-Options', 'DENY');
        $resp->assertHeader('X-Content-Type-Options', 'nosniff');
        $resp->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $resp->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=()');
    }

    public function test_login_invalidates_other_sessions_for_same_user(): void
    {
        $this->seed();
        config(['session.driver' => 'database']);

        $sumit = User::where('email', 'sumit@davya.local')->first();

        DB::table('sessions')->insert([
            ['id' => 'old-device-1', 'user_id' => $sumit->id, 'ip_address' => '1.1.1.1', 'user_agent' => 'x', 'payload' => '', 'last_activity' => time()],
            ['id' => 'old-device-2', 'user_id' => $sumit->id, 'ip_address' => '1.1.1.2', 'user_agent' => 'x', 'payload' => '', 'last_activity' => time()],
        ]);
        $this->assertSame(2, DB::table('sessions')->where('user_id', $sumit->id)->count());

        $listener = app(\App\Listeners\HandleAuthEvents::class);
        $listener->handleLogin(new \Illuminate\Auth\Events\Login('web', $sumit, false));

        $remaining = DB::table('sessions')->where('user_id', $sumit->id)->count();
        $this->assertSame(0, $remaining, 'The two stale sessions should have been removed on login.');
    }

    public function test_failed_login_event_handler_does_not_throw(): void
    {
        $this->seed();
        event(new \Illuminate\Auth\Events\Failed('web', null, ['email' => 'bad@example.com', 'password' => 'wrong']));
        $this->assertTrue(true); // Listener must not raise
    }
}
