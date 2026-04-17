<?php
namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class UsersSplitPctColumnTest extends TestCase
{
    use RefreshDatabase;

    public function test_users_table_has_split_pct_column_with_default_zero(): void
    {
        $this->assertTrue(Schema::hasColumn('users', 'split_pct'));
        $user = \App\Models\User::factory()->create();
        $this->assertSame(0, (int) $user->fresh()->split_pct);
    }
}
