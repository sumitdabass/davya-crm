<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Relaxes slack_message_id to nullable on expenses + investments so that
    // manual (dashboard-entered) rows can exist. The unique index is preserved;
    // MySQL ignores NULLs in a unique index, so Slack dedup still rejects
    // duplicate slack_message_id values.
    //
    // MySQL path uses ALTER TABLE MODIFY COLUMN — touches only the target
    // column, does NOT rebuild the table.
    //
    // SQLite path goes through Schema::table->change(), which rebuilds the
    // table and drops CHECK constraints on other columns (like the enum on
    // investments.direction). After the rebuild we re-assert the enum so
    // tests that rely on DB-level enum enforcement keep passing.
    //
    // ROLLBACK HAZARD: the down() method restores NOT NULL. If any manual
    // (NULL) rows exist at rollback time, it will fail. Backfill a sentinel
    // like 'manual-'.id before rolling back.

    public function up(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE expenses MODIFY COLUMN slack_message_id VARCHAR(50) NULL');
            DB::statement('ALTER TABLE investments MODIFY COLUMN slack_message_id VARCHAR(50) NULL');
            return;
        }

        // Generic path (sqlite, pgsql, etc.): Schema::table->change().
        Schema::table('expenses', function (Blueprint $table) {
            $table->string('slack_message_id', 50)->nullable()->change();
        });
        Schema::table('investments', function (Blueprint $table) {
            $table->string('slack_message_id', 50)->nullable()->change();
        });

        // SQLite drops the direction-enum CHECK during the rebuild above.
        // Re-assert it so DB-level enum enforcement survives.
        if ($driver === 'sqlite') {
            Schema::table('investments', function (Blueprint $table) {
                $table->enum('direction', ['in', 'out'])->change();
            });
        }
    }

    public function down(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE expenses MODIFY COLUMN slack_message_id VARCHAR(50) NOT NULL');
            DB::statement('ALTER TABLE investments MODIFY COLUMN slack_message_id VARCHAR(50) NOT NULL');
            return;
        }

        Schema::table('expenses', function (Blueprint $table) {
            $table->string('slack_message_id', 50)->nullable(false)->change();
        });
        Schema::table('investments', function (Blueprint $table) {
            $table->string('slack_message_id', 50)->nullable(false)->change();
        });

        if ($driver === 'sqlite') {
            Schema::table('investments', function (Blueprint $table) {
                $table->enum('direction', ['in', 'out'])->change();
            });
        }
    }
};
