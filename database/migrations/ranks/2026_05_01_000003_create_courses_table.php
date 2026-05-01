<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $connection = 'ranks';

    public function up(): void
    {
        Schema::connection($this->connection)->create('courses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('university_id')->constrained('universities')->cascadeOnDelete();
            $table->string('name');
            $table->string('code', 32)->nullable();
            $table->timestamps();

            $table->unique(['university_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('courses');
    }
};
