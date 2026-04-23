<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('student_field_sections', function (Blueprint $table) {
            $table->id();
            $table->string('name', 80);
            $table->integer('position')->default(0);
            $table->timestamps();
            $table->index('position');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_field_sections');
    }
};
