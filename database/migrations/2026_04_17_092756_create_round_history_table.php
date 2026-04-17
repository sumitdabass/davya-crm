<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('round_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->enum('round_name', [
                'Online_R1', 'Online_R2', 'Online_R3', 'Online_Sliding', 'Online_Reporting',
                'S2_R1', 'S2_R3', 'Offline_R1', 'Offline_R2',
            ]);
            $table->string('allotted_college', 120)->nullable();
            $table->string('allotted_course', 120)->nullable();
            $table->decimal('seat_fee_amount', 12, 2)->nullable();
            $table->boolean('seat_fee_paid')->default(false);
            $table->timestamp('fee_paid_at')->nullable();
            $table->enum('outcome', [
                'Not Allotted', 'Allotted — Fee Pending', 'Allotted — Fee Paid',
                'Kicked Out — Fee Unpaid', 'Allotted — Frozen (Final)',
            ]);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['student_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('round_history');
    }
};
