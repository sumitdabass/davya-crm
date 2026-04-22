<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MeetingSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_meetings_table_has_expected_columns(): void
    {
        $this->assertTrue(Schema::hasTable('meetings'), 'meetings table must exist');

        foreach ([
            'id', 'student_id', 'owner_id', 'scheduled_at', 'mode', 'status',
            'notes', 'outcome_notes', 'held_at', 'rescheduled_from_id',
            'created_by_id', 'created_at', 'updated_at',
        ] as $col) {
            $this->assertTrue(
                Schema::hasColumn('meetings', $col),
                "meetings.$col must exist",
            );
        }
    }

    public function test_meetings_indexes_are_present(): void
    {
        $connection = DB::connection();
        $driver = $connection->getDriverName();

        if ($driver === 'mysql') {
            $indexes = collect(DB::select('SHOW INDEX FROM meetings'))->pluck('Key_name')->unique()->values()->all();
        } else {
            // SQLite
            $indexes = collect(DB::select("PRAGMA index_list(meetings)"))->pluck('name')->values()->all();
        }

        foreach ([
            'meetings_owner_id_scheduled_at_index',
            'meetings_student_id_scheduled_at_index',
            'meetings_status_scheduled_at_index',
        ] as $expected) {
            $this->assertContains($expected, $indexes, "index $expected must exist");
        }
    }
}
