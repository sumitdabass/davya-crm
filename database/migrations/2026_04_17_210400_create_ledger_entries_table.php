<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->string('account', 60);
            $table->decimal('delta_amount', 12, 2);
            $table->enum('source_type', ['payment', 'expense', 'investment']);
            $table->unsignedBigInteger('source_id');
            $table->string('note', 255)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['account', 'created_at'], 'idx_ledger_account_created');
            $table->index(['source_type', 'source_id'], 'idx_ledger_source');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ledger_entries');
    }
};
