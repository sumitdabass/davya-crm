<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Relaxes slack_message_id to nullable on expenses + investments so that
    // manual (dashboard-entered) rows can exist. The unique index is preserved;
    // MySQL permits multiple NULLs in a unique column, so Slack dedup still
    // rejects duplicate slack_message_id values.
    //
    // ROLLBACK HAZARD: the down() method restores NOT NULL. If any manual
    // (NULL) rows exist at rollback time, it will fail. Backfill a sentinel
    // like 'manual-'.id before rolling back.

    public function up(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->string('slack_message_id', 50)->nullable()->change();
        });
        Schema::table('investments', function (Blueprint $table) {
            $table->string('slack_message_id', 50)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->string('slack_message_id', 50)->nullable(false)->change();
        });
        Schema::table('investments', function (Blueprint $table) {
            $table->string('slack_message_id', 50)->nullable(false)->change();
        });
    }
};
