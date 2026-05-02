<?php

namespace Tests\Feature\Performance;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class RankProbColumnMigrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_column_exists(): void
    {
        $this->assertTrue(Schema::hasColumn('students', 'rank_prob_first_choice'));
    }

    public function test_column_round_trips_values_0_to_100(): void
    {
        $userId = DB::table('users')->value('id');
        $id = DB::table('students')->insertGetId([
            'name' => 'Probe Student',
            'phone' => '9000000001',
            'stage' => 'Lead Captured',
            'lead_source' => 'Test',
            'owner_id' => $userId,
            'referrer_id' => $userId,
            'rank_prob_first_choice' => 87,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $row = DB::table('students')->where('id', $id)->first();
        $this->assertSame(87, (int) $row->rank_prob_first_choice);
    }

    public function test_column_accepts_null(): void
    {
        $userId = DB::table('users')->value('id');
        $id = DB::table('students')->insertGetId([
            'name' => 'Null Probe',
            'phone' => '9000000002',
            'stage' => 'Lead Captured',
            'lead_source' => 'Test',
            'owner_id' => $userId,
            'referrer_id' => $userId,
            'rank_prob_first_choice' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $row = DB::table('students')->where('id', $id)->first();
        $this->assertNull($row->rank_prob_first_choice);
    }
}
