<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $connection = 'ranks';

    public function up(): void
    {
        Schema::connection($this->connection)->create('seats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('university_id')->constrained('universities')->cascadeOnDelete();
            $table->foreignId('course_id')->constrained('courses')->cascadeOnDelete();
            $table->unsignedSmallInteger('year');
            $table->foreignId('institute_id')->constrained('institutes');
            $table->foreignId('branch_id')->constrained('branches');
            $table->unsignedInteger('seat_count');
            $table->text('source_note')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->unique(
                ['university_id', 'course_id', 'year', 'institute_id', 'branch_id'],
                'seats_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('seats');
    }
};
