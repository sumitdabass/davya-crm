<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $connection = 'ranks';

    public function up(): void
    {
        Schema::connection($this->connection)->create('cutoffs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('university_id')->constrained('universities')->cascadeOnDelete();
            $table->foreignId('course_id')->constrained('courses')->cascadeOnDelete();
            $table->foreignId('qualifying_exam_id')->constrained('qualifying_exams');
            $table->foreignId('admission_process_id')->constrained('admission_processes');
            $table->unsignedSmallInteger('year');
            // String, not enum: JAC publishes rounds 1-5 (+ IPU 'sliding'); an enum
            // here under-specifies reality and becomes a rejecting CHECK on SQLite.
            $table->string('round', 16);
            $table->foreignId('institute_id')->constrained('institutes');
            $table->foreignId('branch_id')->constrained('branches');
            $table->enum('shift', ['I', 'II'])->nullable();
            $table->enum('region', ['delhi', 'outside_delhi']);
            $table->unsignedInteger('min_rank');
            $table->unsignedInteger('max_rank');
            $table->enum('source', ['official', 'predicted', 'validated'])->default('official');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->softDeletes();
            $table->timestamps();

            $table->unique(
                ['university_id', 'course_id', 'qualifying_exam_id', 'admission_process_id', 'year', 'round', 'institute_id', 'branch_id', 'shift', 'region'],
                'cutoffs_unique'
            );
            $table->index(['year', 'round', 'region']);
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('cutoffs');
    }
};
