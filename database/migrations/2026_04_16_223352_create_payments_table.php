<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->enum('type', ['advance', 'partial', 'full', 'refund']);
            $table->decimal('amount', 12, 2);
            $table->enum('mode', ['cash', 'upi', 'bank_transfer', 'card', 'cheque', 'other'])->nullable();
            $table->string('reference_number', 80)->nullable();
            $table->dateTime('received_at');
            $table->string('proof_drive_url', 500)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('recorded_by_user_id')->constrained('users');
            $table->timestamps();
            $table->index(['student_id', 'received_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
