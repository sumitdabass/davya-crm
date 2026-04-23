<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Add nullable FK first; backfill runs in a later migration.
        Schema::table('students', function (Blueprint $t) {
            $t->foreignId('stage_id')->nullable()->after('stage')->constrained('stages')->nullOnDelete();
            $t->index('stage_id');
        });

        // Widen ENUM → VARCHAR on MySQL so admin-added stages can write to the cache column.
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE students MODIFY stage VARCHAR(80) NOT NULL");
        }
        // SQLite stores enums as TEXT already — no-op.
    }

    public function down(): void
    {
        Schema::table('students', function (Blueprint $t) {
            $t->dropForeign(['stage_id']);
            $t->dropIndex(['stage_id']);
            $t->dropColumn('stage_id');
        });
        // Intentionally not re-narrowing stage to ENUM on down() — too risky with custom values present.
    }
};
