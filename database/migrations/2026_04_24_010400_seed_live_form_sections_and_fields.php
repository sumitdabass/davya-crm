<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        $now = now();

        // 1) Ensure the 5 missing sections that mirror the live StudentResource form tabs exist.
        $desiredSections = [
            'Source & Stage', 'Deal', 'Counselling', 'History', 'Closure',
        ];
        $maxPos = (int) DB::table('student_field_sections')->max('position');
        foreach ($desiredSections as $name) {
            $exists = DB::table('student_field_sections')->where('name', $name)->exists();
            if (!$exists) {
                $maxPos++;
                DB::table('student_field_sections')->insert([
                    'name' => $name, 'position' => $maxPos,
                    'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        }

        $sections = DB::table('student_field_sections')->pluck('id', 'name');

        // 2) Move category + state from Identity -> Academic (to match where the live form renders them).
        $academicId = $sections['Academic'];
        $pos = (int) DB::table('student_fields')->where('section_id', $academicId)->max('position');
        foreach (['category', 'state'] as $key) {
            $field = DB::table('student_fields')->where('key', $key)->first();
            if ($field && $field->section_id != $academicId) {
                $pos++;
                DB::table('student_fields')->where('id', $field->id)->update([
                    'section_id' => $academicId, 'position' => $pos, 'updated_at' => $now,
                ]);
            }
        }

        // 3) Seed the 24 built-ins that mirror the remaining live-form fields.
        //    Options are stored in labeled [{value,label}] format to match FieldRenderer + optionsFor().
        $label = fn (array $vals) => array_map(fn ($v) => ['value' => $v, 'label' => $v], $vals);

        $toSeed = [
            'Identity' => [
                ['key' => 'email', 'label' => 'Email', 'type' => 'email', 'built_in_column' => 'email', 'options' => null, 'required' => false],
            ],
            'Source & Stage' => [
                ['key' => 'owner_id',         'label' => 'Owner',            'type' => 'dropdown', 'built_in_column' => 'owner_id',         'options' => null, 'required' => true],
                ['key' => 'lead_source',      'label' => 'Lead Source',      'type' => 'dropdown', 'built_in_column' => 'lead_source',      'options' => null, 'required' => true],
                ['key' => 'referrer_name',    'label' => 'Referrer Name',    'type' => 'text',     'built_in_column' => 'referrer_name',    'options' => null, 'required' => false],
                ['key' => 'stage',            'label' => 'Stage',            'type' => 'dropdown', 'built_in_column' => 'stage',            'options' => null, 'required' => true],
                ['key' => 'student_response', 'label' => 'Student Response', 'type' => 'dropdown', 'built_in_column' => 'student_response', 'options' => $label(['Ready','Not Interested','Needs Time']), 'required' => false],
            ],
            'Academic' => [
                ['key' => 'exam_appeared',  'label' => 'Exam Appeared', 'type' => 'text', 'built_in_column' => 'exam_appeared',  'options' => null, 'required' => false],
                ['key' => 'twelfth_marks',  'label' => '12th Marks',    'type' => 'text', 'built_in_column' => 'twelfth_marks',  'options' => null, 'required' => false],
                ['key' => 'rank',           'label' => 'Rank',          'type' => 'text', 'built_in_column' => 'rank',           'options' => null, 'required' => false],
                ['key' => 'preference_r1',  'label' => '1st Choice',    'type' => 'text', 'built_in_column' => 'preference_r1',  'options' => null, 'required' => true],
                ['key' => 'preference_r2',  'label' => '2nd Choice',    'type' => 'text', 'built_in_column' => 'preference_r2',  'options' => null, 'required' => false],
                ['key' => 'preference_r3',  'label' => '3rd Choice',    'type' => 'text', 'built_in_column' => 'preference_r3',  'options' => null, 'required' => false],
            ],
            'Deal' => [
                ['key' => 'deal_amount', 'label' => 'Deal Amount', 'type' => 'number',   'built_in_column' => 'deal_amount', 'options' => null, 'required' => false],
                ['key' => 'plan',        'label' => 'Plan',        'type' => 'dropdown', 'built_in_column' => 'plan',        'options' => $label(['Online','Offline','All']), 'required' => false],
            ],
            'Counselling' => [
                ['key' => 'is_ipu_registered', 'label' => 'IPU Registered',  'type' => 'checkbox', 'built_in_column' => 'is_ipu_registered', 'options' => null, 'required' => false],
                ['key' => 'ipu_user_id',       'label' => 'IPU User ID',     'type' => 'text',     'built_in_column' => 'ipu_user_id',       'options' => null, 'required' => false],
                ['key' => 'ipu_login_code',    'label' => 'IPU Login Code',  'type' => 'text',     'built_in_column' => 'ipu_login_code',    'options' => null, 'required' => false],
                ['key' => 'current_round',     'label' => 'Current Round',   'type' => 'text',     'built_in_column' => 'current_round',     'options' => null, 'required' => false],
                ['key' => 'seat_fee_due',      'label' => 'Seat Fee Due',    'type' => 'checkbox', 'built_in_column' => 'seat_fee_due',      'options' => null, 'required' => false],
            ],
            'Closure' => [
                ['key' => 'close_reason',    'label' => 'Close Reason',    'type' => 'dropdown', 'built_in_column' => 'close_reason',    'options' => $label(['Not Interested','Backed Out — Forfeit','Backed Out — Partial Refund','Completed','Other']), 'required' => false],
                ['key' => 'refund_amount',   'label' => 'Refund Amount',   'type' => 'number',   'built_in_column' => 'refund_amount',   'options' => null, 'required' => false],
                ['key' => 're_entry_reason', 'label' => 'Re-entry Reason', 'type' => 'textarea', 'built_in_column' => 're_entry_reason', 'options' => null, 'required' => false],
                ['key' => 'description',     'label' => 'Description',     'type' => 'textarea', 'built_in_column' => 'description',     'options' => null, 'required' => false],
                ['key' => 'extra_notes',     'label' => 'Extra Notes',     'type' => 'textarea', 'built_in_column' => 'extra_notes',     'options' => null, 'required' => false],
            ],
        ];

        foreach ($toSeed as $sectionName => $fields) {
            $sid = $sections[$sectionName] ?? null;
            if (!$sid) continue;
            $pos = (int) DB::table('student_fields')->where('section_id', $sid)->max('position');
            foreach ($fields as $f) {
                if (DB::table('student_fields')->where('key', $f['key'])->exists()) continue;
                $pos++;
                DB::table('student_fields')->insert([
                    'section_id' => $sid,
                    'key' => $f['key'],
                    'label' => $f['label'],
                    'type' => $f['type'],
                    'is_required' => $f['required'],
                    'is_built_in' => true,
                    'built_in_column' => $f['built_in_column'],
                    'options' => $f['options'] !== null ? json_encode($f['options']) : null,
                    'show_in_table' => false,
                    'show_in_kanban' => false,
                    'show_in_import' => false,
                    'position' => $pos,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }

        // 4) Backfill options on pre-existing `category` built-in (seeded earlier without options
        //    in the original 010300 migration? No — it had labeled options. This is a no-op safety
        //    net in case any environment drifted to flat or empty.)
        $category = DB::table('student_fields')->where('key', 'category')->first();
        if ($category) {
            $opts = is_string($category->options) ? json_decode($category->options, true) : $category->options;
            $needsFix = empty($opts) || (isset($opts[0]) && !is_array($opts[0]));
            if ($needsFix) {
                DB::table('student_fields')->where('id', $category->id)->update([
                    'options' => json_encode($label(['Delhi','Outside'])),
                    'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        // Drop the 24 seeded fields by key so user-created customs are preserved.
        $keys = [
            'email',
            'owner_id', 'lead_source', 'referrer_name', 'stage', 'student_response',
            'exam_appeared', 'twelfth_marks', 'rank', 'preference_r1', 'preference_r2', 'preference_r3',
            'deal_amount', 'plan',
            'is_ipu_registered', 'ipu_user_id', 'ipu_login_code', 'current_round', 'seat_fee_due',
            'close_reason', 'refund_amount', 're_entry_reason', 'description', 'extra_notes',
        ];
        DB::table('student_fields')->whereIn('key', $keys)->where('is_built_in', true)->delete();

        // Reverse the category + state move: send them back to Identity.
        $identityId = DB::table('student_field_sections')->where('name', 'Identity')->value('id');
        if ($identityId) {
            DB::table('student_fields')->whereIn('key', ['category', 'state'])->update(['section_id' => $identityId]);
        }

        // Drop the 5 added sections only if empty (protects any user-created fields in them).
        foreach (['Source & Stage', 'Deal', 'Counselling', 'History', 'Closure'] as $name) {
            $sid = DB::table('student_field_sections')->where('name', $name)->value('id');
            if (!$sid) continue;
            $hasFields = DB::table('student_fields')->where('section_id', $sid)->exists();
            if (!$hasFields) {
                DB::table('student_field_sections')->where('id', $sid)->delete();
            }
        }
    }
};
