<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meetings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('owner_id')->constrained('users');
            $table->dateTime('scheduled_at');
            $table->enum('mode', ['in_person', 'phone', 'video', 'whatsapp'])->default('in_person');
            $table->enum('status', ['scheduled', 'held', 'no_show', 'rescheduled', 'cancelled'])->default('scheduled');
            $table->text('notes')->nullable();
            $table->text('outcome_notes')->nullable();
            $table->dateTime('held_at')->nullable();
            $table->foreignId('rescheduled_from_id')->nullable()->constrained('meetings')->nullOnDelete();
            $table->foreignId('created_by_id')->constrained('users');
            $table->timestamps();

            $table->index(['owner_id', 'scheduled_at']);
            $table->index(['student_id', 'scheduled_at']);
            $table->index(['status', 'scheduled_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meetings');
    }
};
