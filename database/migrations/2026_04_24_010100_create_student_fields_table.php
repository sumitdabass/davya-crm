<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('student_fields', function (Blueprint $table) {
            $table->id();
            $table->foreignId('section_id')->nullable()->constrained('student_field_sections')->nullOnDelete();
            $table->string('key', 80)->unique();
            $table->string('label', 120);
            $table->enum('type', ['text','textarea','number','date','email','dropdown','checkbox','multiselect']);
            $table->boolean('is_required')->default(false);
            $table->boolean('is_built_in')->default(false);
            $table->string('built_in_column', 40)->nullable();
            $table->json('options')->nullable();
            $table->boolean('show_in_table')->default(false);
            $table->boolean('show_in_kanban')->default(false);
            $table->boolean('show_in_import')->default(false);
            $table->integer('position')->default(0);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
            $table->index(['section_id', 'position']);
            $table->index('archived_at');
            $table->index('is_built_in');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_fields');
    }
};
