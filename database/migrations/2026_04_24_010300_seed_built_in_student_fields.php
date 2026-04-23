<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        $now = now();
        $identityId = DB::table('student_field_sections')->insertGetId(['name' => 'Identity', 'position' => 0, 'created_at' => $now, 'updated_at' => $now]);
        $academicId = DB::table('student_field_sections')->insertGetId(['name' => 'Academic', 'position' => 1, 'created_at' => $now, 'updated_at' => $now]);

        $rows = [
            ['phone',        'Phone',           'text',     true,  'phone',        $identityId, 0, null],
            ['name',         'Name',            'text',     true,  'name',         $identityId, 1, null],
            ['father_name',  'Guardian Name',   'text',     false, 'father_name',  $identityId, 2, null],
            ['phone_2',      'Alternate Phone', 'text',     false, 'phone_2',      $identityId, 3, null],
            ['category',     'Zone',            'dropdown', false, 'category',     $identityId, 4, json_encode([['value' => 'Delhi', 'label' => 'Delhi'], ['value' => 'Outside', 'label' => 'Outside']])],
            ['state',        'State',           'text',     false, 'state',        $identityId, 5, null],
            ['course',       'Course',          'text',     false, 'course',       $academicId, 0, null],
            ['final_course', 'Final Course',    'text',     false, 'final_course', $academicId, 1, null],
        ];

        foreach ($rows as [$key, $label, $type, $req, $col, $sectionId, $pos, $opts]) {
            DB::table('student_fields')->insert([
                'section_id' => $sectionId,
                'key' => $key,
                'label' => $label,
                'type' => $type,
                'is_required' => $req,
                'is_built_in' => true,
                'built_in_column' => $col,
                'options' => $opts,
                'show_in_table' => false,
                'show_in_kanban' => false,
                'show_in_import' => true,
                'position' => $pos,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        DB::table('student_fields')->where('is_built_in', true)->delete();
        DB::table('student_field_sections')->whereIn('name', ['Identity', 'Academic'])->delete();
    }
};
