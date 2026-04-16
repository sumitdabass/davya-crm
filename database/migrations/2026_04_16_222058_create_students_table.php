<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('students', function (Blueprint $table) {
            $table->id();
            $table->string('phone', 15)->unique();
            $table->string('name', 120);
            $table->string('father_name', 120)->nullable();
            $table->string('phone_2', 15)->nullable();
            $table->foreignId('owner_id')->constrained('users');
            $table->foreignId('referrer_id')->constrained('users');
            $table->enum('stage', [
                'Lead Captured','Meeting Scheduled','Meeting Done','Onboarded',
                'University Registration','Counselling In Progress','Seat Allotted',
                'Full Payment Received','Admission Confirmed','Closed',
            ])->default('Lead Captured');
            $table->string('lead_source', 60);
            $table->enum('student_response', ['Ready','Not Interested','Needs Time'])->nullable();
            $table->string('exam_appeared', 40)->nullable();
            $table->string('twelfth_marks', 20)->nullable();
            $table->enum('category', ['Delhi','Outside'])->nullable();
            $table->string('course', 80)->nullable();
            $table->string('preference_r1', 120)->nullable();
            $table->string('preference_r2', 120)->nullable();
            $table->string('preference_r3', 120)->nullable();
            $table->decimal('deal_amount', 12, 2)->nullable();
            $table->enum('plan', ['Online','Offline','All'])->nullable();
            $table->boolean('is_ipu_registered')->nullable();
            $table->string('ipu_user_id', 60)->nullable();
            $table->text('ipu_password')->nullable();
            $table->string('current_round', 40)->nullable();
            $table->boolean('seat_fee_due')->default(false);
            $table->string('final_college', 120)->nullable();
            $table->string('final_course', 120)->nullable();
            $table->date('admission_date')->nullable();
            $table->dateTime('meeting_date')->nullable();
            $table->string('meeting_location', 120)->nullable();
            $table->boolean('address_sent')->nullable();
            $table->boolean('office_visit')->nullable();
            $table->enum('close_reason', ['Not Interested','Backed Out — Forfeit','Backed Out — Partial Refund','Completed','Other'])->nullable();
            $table->decimal('refund_amount', 12, 2)->nullable();
            $table->text('re_entry_reason')->nullable();
            $table->text('description')->nullable();
            $table->text('extra_notes')->nullable();
            $table->timestamps();

            $table->index(['owner_id', 'stage']);
            $table->index('stage');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('students');
    }
};
