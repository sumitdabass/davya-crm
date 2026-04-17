<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('investments', function (Blueprint $table) {
            $table->id();
            $table->string('asset_name', 80);
            $table->decimal('amount', 12, 2);
            $table->enum('direction', ['in', 'out']);
            $table->timestamp('transacted_at');
            $table->string('slack_message_id', 50)->unique();
            $table->text('raw_input')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('investments');
    }
};
