<?php
// tests/Feature/Pipeline/BackfillStudentStageIdTest.php
namespace Tests\Feature\Pipeline;

use App\Models\Pipeline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BackfillStudentStageIdTest extends TestCase
{
    use RefreshDatabase;

    public function test_direct_backfill_call_maps_stage_string_to_stage_id(): void
    {
        // After the stages seed migration runs (automatically via RefreshDatabase),
        // directly test the backfill mechanism by inserting a legacy-shape row
        // and running the UPDATE the backfill migration would have run.
        $p = Pipeline::default();
        $scheduledStageId = $p->stages()->where('name', 'Meeting Scheduled')->value('id');

        $ownerId = \App\Models\User::factory()->create()->id;
        DB::table('students')->insert([
            'name' => 'Legacy Student', 'phone' => '9000000002',
            'owner_id' => $ownerId, 'referrer_id' => $ownerId,
            'lead_source' => 'test',
            'stage' => 'Meeting Scheduled', 'stage_id' => null,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // Run the same backfill the migration would execute.
        DB::statement("
            UPDATE students
            SET stage_id = (SELECT id FROM stages WHERE stages.name = students.stage AND stages.pipeline_id = ?)
            WHERE stage_id IS NULL
        ", [$p->id]);

        $row = DB::table('students')->where('phone', '9000000002')->first();
        $this->assertSame($scheduledStageId, $row->stage_id);
    }

    public function test_migration_file_backfills_existing_students(): void
    {
        // Insert legacy-shape rows (stage_id NULL) then re-run the migration
        // file directly — proves edits to the migration's up() are exercised,
        // not just the copy-pasted SQL in the other tests.
        $p = Pipeline::default();
        $scheduledStageId = $p->stages()->where('name', 'Meeting Scheduled')->value('id');
        $ownerId = \App\Models\User::factory()->create()->id;

        DB::table('students')->insert([
            [
                'name' => 'Pre-migration A', 'phone' => '9000000010',
                'owner_id' => $ownerId, 'referrer_id' => $ownerId,
                'lead_source' => 'test',
                'stage' => 'Meeting Scheduled', 'stage_id' => null,
                'created_at' => now(), 'updated_at' => now(),
            ],
            [
                'name' => 'Pre-migration B (orphan)', 'phone' => '9000000011',
                'owner_id' => $ownerId, 'referrer_id' => $ownerId,
                'lead_source' => 'test',
                'stage' => 'Defunct Legacy Stage', 'stage_id' => null,
                'created_at' => now(), 'updated_at' => now(),
            ],
        ]);

        // Directly invoke the backfill migration's up() via require — catches
        // any drift between the test's inline SQL and the migration file.
        $migration = require database_path('migrations/2026_04_23_100500_backfill_student_stage_id.php');
        $migration->up();

        $a = DB::table('students')->where('phone', '9000000010')->first();
        $b = DB::table('students')->where('phone', '9000000011')->first();
        $leadCapturedId = $p->stages()->where('name', 'Lead Captured')->value('id');

        $this->assertSame($scheduledStageId, $a->stage_id);
        $this->assertSame($leadCapturedId, $b->stage_id);
        $this->assertSame('Lead Captured', $b->stage);
    }

    public function test_orphan_row_is_parked_at_lead_captured(): void
    {
        // A row whose legacy stage doesn't match any seeded stage name should
        // land at Lead Captured after backfill.
        $p = Pipeline::default();
        $leadCapturedId = $p->stages()->where('name', 'Lead Captured')->value('id');

        $ownerId = \App\Models\User::factory()->create()->id;
        DB::table('students')->insert([
            'name' => 'Orphan', 'phone' => '9000000003',
            'owner_id' => $ownerId, 'referrer_id' => $ownerId,
            'lead_source' => 'test',
            'stage' => 'Some Defunct Stage Name', 'stage_id' => null,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // Backfill with the same semantics as the migration.
        DB::statement("
            UPDATE students
            SET stage_id = (SELECT id FROM stages WHERE stages.name = students.stage AND stages.pipeline_id = ?)
            WHERE stage_id IS NULL
        ", [$p->id]);

        DB::table('students')->whereNull('stage_id')->update([
            'stage_id' => $leadCapturedId,
            'stage' => 'Lead Captured',
        ]);

        $row = DB::table('students')->where('phone', '9000000003')->first();
        $this->assertSame($leadCapturedId, $row->stage_id);
        $this->assertSame('Lead Captured', $row->stage);
    }
}
