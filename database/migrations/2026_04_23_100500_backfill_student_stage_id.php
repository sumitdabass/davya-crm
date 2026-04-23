<?php
// database/migrations/2026_04_23_100500_backfill_student_stage_id.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

return new class extends Migration {
    public function up(): void
    {
        $pipelineId = DB::table('pipelines')->where('is_default', true)->value('id');
        if (! $pipelineId) return;

        DB::transaction(function () use ($pipelineId) {
            // Backfill by exact stage-name match (portable between MySQL and SQLite).
            DB::statement("
                UPDATE students
                SET stage_id = (
                    SELECT id FROM stages
                    WHERE stages.name = students.stage AND stages.pipeline_id = ?
                )
                WHERE stage_id IS NULL
            ", [$pipelineId]);

            // Any rows still NULL (orphan legacy value)? Park them at Lead Captured.
            $leadCapturedId = DB::table('stages')
                ->where('pipeline_id', $pipelineId)->where('name', 'Lead Captured')->value('id');

            $orphans = DB::table('students')->whereNull('stage_id')->count();
            if ($orphans > 0) {
                DB::table('students')->whereNull('stage_id')->update([
                    'stage_id' => $leadCapturedId,
                    'stage' => 'Lead Captured',
                ]);
                Log::warning("Backfill parked $orphans orphan student(s) at Lead Captured");
            }
        });
    }

    public function down(): void
    {
        DB::table('students')->update(['stage_id' => null]);
    }
};
