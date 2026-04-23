<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('student_field_values', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->foreignId('student_field_id')->constrained('student_fields')->cascadeOnDelete();
            $table->text('value_text')->nullable();
            $table->decimal('value_number', 20, 4)->nullable();
            $table->date('value_date')->nullable();
            $table->json('value_json')->nullable();
            $table->timestamps();
            $table->unique(['student_id', 'student_field_id']);
            $table->index(['student_field_id', 'value_text'], 'sfv_field_text_idx');
            $table->index(['student_field_id', 'value_number'], 'sfv_field_number_idx');
            $table->index(['student_field_id', 'value_date'], 'sfv_field_date_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_field_values');
    }
};
