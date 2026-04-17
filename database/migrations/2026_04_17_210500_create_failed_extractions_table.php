<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('failed_extractions', function (Blueprint $table) {
            $table->id();
            $table->string('slack_message_id', 50);  // NOT unique — same msg may fail repeatedly
            $table->string('slack_channel', 60)->nullable();
            $table->text('raw_input')->nullable();
            $table->string('error_reason', 255)->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('failed_extractions');
    }
};
