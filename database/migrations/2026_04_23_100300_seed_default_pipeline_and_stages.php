<?php
// database/migrations/2026_04_23_100300_seed_default_pipeline_and_stages.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        if (DB::table('pipelines')->where('name', 'IPU Admission')->exists()) {
            return;
        }

        DB::transaction(function () {
            $now = now();
            $pipelineId = DB::table('pipelines')->insertGetId([
                'name' => 'IPU Admission',
                'record_label' => 'Student',
                'is_default' => true,
                'created_at' => $now, 'updated_at' => $now,
            ]);

            $stages = [
                ['Lead Captured',              'OPEN'],
                ['Meeting Scheduled',          'OPEN'],
                ['Meeting Done',               'OPEN'],
                ['Advance Received',           'OPEN'],
                ['MQ',                         'OPEN'],
                ['Round 1',                    'OPEN'],
                ['Round 2',                    'OPEN'],
                ['Round 3',                    'OPEN'],
                ['Sliding',                    'OPEN'],
                ['Offline',                    'OPEN'],
                ['Seat Allotted',              'OPEN'],
                ['Complete Payment Received',  'CLOSED_WON'],
                ['Closed',                     'CLOSED_LOST'],
            ];

            $rows = [];
            foreach ($stages as $i => [$name, $type]) {
                $rows[] = [
                    'pipeline_id' => $pipelineId,
                    'name' => $name,
                    'stage_type' => $type,
                    'display_order' => $i + 1,
                    'created_at' => $now, 'updated_at' => $now,
                ];
            }
            DB::table('stages')->insert($rows);
        });
    }

    public function down(): void
    {
        DB::table('stages')->where('pipeline_id', function ($q) {
            $q->select('id')->from('pipelines')->where('name', 'IPU Admission');
        })->delete();
        DB::table('pipelines')->where('name', 'IPU Admission')->delete();
    }
};
