<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->boolean('flagged_for_review')->default(false)->after('stage');
            $table->string('flag_reason', 40)->nullable()->after('flagged_for_review');

            // Drop the unique constraint on phone — the LeadIntakeService now enforces
            // dedup with ownership-priority rules, and the head-vs-head "flagged for
            // review" case deliberately allows two rows with the same phone to coexist.
            $table->dropUnique(['phone']);
            $table->index('phone');
        });

        Schema::create('duplicate_flags', function (Blueprint $table) {
            $table->id();
            $table->string('phone', 24)->index();
            // Nullable + nullOnDelete so the flag record survives deletion of either
            // side; we still need the audit trail after resolution collapses two
            // students into one.
            $table->foreignId('student_a_id')->nullable()->constrained('students')->nullOnDelete();
            $table->foreignId('student_b_id')->nullable()->constrained('students')->nullOnDelete();
            $table->string('reason', 40);
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('resolved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('kept_student_id')->nullable()->constrained('students')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('duplicate_flags');

        Schema::table('students', function (Blueprint $table) {
            $table->dropIndex(['phone']);
            $table->unique('phone');
            $table->dropColumn(['flagged_for_review', 'flag_reason']);
        });
    }
};
