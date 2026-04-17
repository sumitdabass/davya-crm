<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->string('slack_message_id', 50)->nullable()->after('recorded_by_user_id');
            $table->text('raw_input')->nullable()->after('slack_message_id');
            $table->unique('slack_message_id', 'payments_slack_message_id_unique');
            $table->foreignId('recorded_by_user_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropUnique('payments_slack_message_id_unique');
            $table->dropColumn(['slack_message_id', 'raw_input']);
            // Intentionally leave recorded_by_user_id nullable on rollback — re-tightening would fail if Slack rows exist.
        });
    }
};
