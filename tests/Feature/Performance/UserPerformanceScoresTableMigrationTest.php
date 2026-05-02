<?php

namespace Tests\Feature\Performance;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class UserPerformanceScoresTableMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_table_and_columns_exist(): void
    {
        $this->assertTrue(Schema::hasTable('user_performance_scores'));

        foreach ([
            'id', 'user_id', 'period_start', 'period_end',
            'score', 'tier', 'signal_breakdown', 'team_max_snapshot',
            'calculated_at', 'created_at', 'updated_at',
        ] as $col) {
            $this->assertTrue(
                Schema::hasColumn('user_performance_scores', $col),
                "Column $col missing"
            );
        }
    }

    public function test_unique_constraint_on_user_and_period_start(): void
    {
        $this->seed();
        $userId = DB::table('users')->value('id');

        DB::table('user_performance_scores')->insert([
            'user_id' => $userId,
            'period_start' => '2026-05-01',
            'period_end' => '2026-05-31',
            'score' => 70,
            'tier' => 'Strong',
            'signal_breakdown' => json_encode(['closed_won' => 5]),
            'team_max_snapshot' => json_encode(['closed_won' => 10]),
            'calculated_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(QueryException::class);

        DB::table('user_performance_scores')->insert([
            'user_id' => $userId,
            'period_start' => '2026-05-01',
            'period_end' => '2026-05-31',
            'score' => 80,
            'tier' => 'Strong',
            'signal_breakdown' => json_encode(['closed_won' => 6]),
            'team_max_snapshot' => json_encode(['closed_won' => 10]),
            'calculated_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_cascade_on_user_delete(): void
    {
        $this->seed();
        $userId = DB::table('users')->insertGetId([
            'name' => 'Doomed User',
            'email' => 'doomed@test.local',
            'password' => bcrypt('password'),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('user_performance_scores')->insert([
            'user_id' => $userId,
            'period_start' => '2026-05-01',
            'period_end' => '2026-05-31',
            'score' => 70, 'tier' => 'Strong',
            'signal_breakdown' => json_encode([]),
            'team_max_snapshot' => json_encode([]),
            'calculated_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('users')->where('id', $userId)->delete();

        $this->assertSame(
            0,
            DB::table('user_performance_scores')->where('user_id', $userId)->count()
        );
    }
}
