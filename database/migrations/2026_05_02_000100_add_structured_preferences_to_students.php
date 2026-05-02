<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->string('preference_r1_college', 200)->nullable()->after('preference_r1');
            $table->string('preference_r1_branch', 200)->nullable()->after('preference_r1_college');
            $table->string('preference_r2_college', 200)->nullable()->after('preference_r2');
            $table->string('preference_r2_branch', 200)->nullable()->after('preference_r2_college');
            $table->string('preference_r3_college', 200)->nullable()->after('preference_r3');
            $table->string('preference_r3_branch', 200)->nullable()->after('preference_r3_college');
        });

        // Best-effort backfill: existing freeform preference_r{n} values are
        // moved into preference_r{n}_college so legacy data isn't lost. The
        // operator can revisit and split college/branch later.
        \DB::statement('UPDATE students SET preference_r1_college = preference_r1 WHERE preference_r1 IS NOT NULL AND preference_r1 != ""');
        \DB::statement('UPDATE students SET preference_r2_college = preference_r2 WHERE preference_r2 IS NOT NULL AND preference_r2 != ""');
        \DB::statement('UPDATE students SET preference_r3_college = preference_r3 WHERE preference_r3 IS NOT NULL AND preference_r3 != ""');
    }

    public function down(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->dropColumn([
                'preference_r1_college', 'preference_r1_branch',
                'preference_r2_college', 'preference_r2_branch',
                'preference_r3_college', 'preference_r3_branch',
            ]);
        });
    }
};
