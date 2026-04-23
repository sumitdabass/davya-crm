<?php
// database/migrations/2026_04_23_100400_seed_default_transition_rules.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        $now = now();
        $pipelineId = DB::table('pipelines')->where('is_default', true)->value('id');
        if (! $pipelineId) return;

        // Idempotency — don't re-seed if any rules already exist for this pipeline.
        if (DB::table('stage_transition_rules')->where('pipeline_id', $pipelineId)->exists()) return;

        $stageId = fn (string $name) => DB::table('stages')
            ->where('pipeline_id', $pipelineId)->where('name', $name)->value('id');

        DB::transaction(function () use ($pipelineId, $now, $stageId) {
            // Rule 1: Any → Closed requires close_reason (HARD)
            $id1 = DB::table('stage_transition_rules')->insertGetId([
                'pipeline_id' => $pipelineId, 'name' => 'Closed requires reason',
                'from_stage_id' => null, 'to_stage_id' => $stageId('Closed'),
                'severity' => 'HARD', 'is_active' => true,
                'created_at' => $now, 'updated_at' => $now,
            ]);
            DB::table('stage_transition_conditions')->insert([
                'rule_id' => $id1, 'condition_type' => 'FIELD_CHECK',
                'field_or_relation' => 'close_reason', 'operator' => 'is_not_empty',
                'value' => null, 'display_order' => 0,
                'created_at' => $now, 'updated_at' => $now,
            ]);

            // Rule 2: Closed → Any requires re_entry_reason (HARD)
            $id2 = DB::table('stage_transition_rules')->insertGetId([
                'pipeline_id' => $pipelineId, 'name' => 'Re-opening requires re-entry reason',
                'from_stage_id' => $stageId('Closed'), 'to_stage_id' => null,
                'severity' => 'HARD', 'is_active' => true,
                'created_at' => $now, 'updated_at' => $now,
            ]);
            DB::table('stage_transition_conditions')->insert([
                'rule_id' => $id2, 'condition_type' => 'FIELD_CHECK',
                'field_or_relation' => 're_entry_reason', 'operator' => 'is_not_empty',
                'value' => null, 'display_order' => 0,
                'created_at' => $now, 'updated_at' => $now,
            ]);

            // Rule 3: Any → Meeting Scheduled wants a future meeting (SOFT)
            $id3 = DB::table('stage_transition_rules')->insertGetId([
                'pipeline_id' => $pipelineId, 'name' => 'Meeting Scheduled needs a future meeting',
                'from_stage_id' => null, 'to_stage_id' => $stageId('Meeting Scheduled'),
                'severity' => 'SOFT', 'is_active' => true,
                'created_at' => $now, 'updated_at' => $now,
            ]);
            DB::table('stage_transition_conditions')->insert([
                'rule_id' => $id3, 'condition_type' => 'HAS_RELATION',
                'field_or_relation' => 'meetings', 'operator' => 'has_where',
                'value' => json_encode([
                    'status' => 'scheduled',
                    'scheduled_at_gte' => 'now',
                    'count_min' => 1,
                ]),
                'display_order' => 0,
                'created_at' => $now, 'updated_at' => $now,
            ]);

            // Rule 4: Any → Sliding wants prior allotment (SOFT)
            $id4 = DB::table('stage_transition_rules')->insertGetId([
                'pipeline_id' => $pipelineId, 'name' => 'Sliding needs prior allotment',
                'from_stage_id' => null, 'to_stage_id' => $stageId('Sliding'),
                'severity' => 'SOFT', 'is_active' => true,
                'created_at' => $now, 'updated_at' => $now,
            ]);
            DB::table('stage_transition_conditions')->insert([
                'rule_id' => $id4, 'condition_type' => 'HAS_RELATION',
                'field_or_relation' => 'roundHistory', 'operator' => 'has_where',
                'value' => json_encode([
                    'outcome_like' => 'Allotted%',
                    'count_min' => 1,
                ]),
                'display_order' => 0,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        });
    }

    public function down(): void
    {
        $pipelineId = DB::table('pipelines')->where('is_default', true)->value('id');
        if (! $pipelineId) return;
        DB::table('stage_transition_rules')->where('pipeline_id', $pipelineId)->delete();
    }
};
