<?php
namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SplitPctSeedTest extends TestCase
{
    use RefreshDatabase;

    public function test_nikhil_seed_sets_split_pct_to_60(): void
    {
        $this->seed();
        $this->assertSame(60, (int) User::where('email','nikhil@davya.local')->first()->split_pct);
    }

    public function test_other_users_split_pct_stays_zero_by_default(): void
    {
        $this->seed();
        foreach (['sumit','sonam','nisha','poonam','neetu','kapil'] as $slug) {
            $u = User::where('email', "$slug@davya.local")->first();
            $this->assertSame(0, (int) $u->split_pct, "$slug should be 0, got {$u->split_pct}");
        }
    }
}
