<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('pipelines', function (Blueprint $t) {
            $t->id();
            $t->string('name', 120);
            $t->string('icon', 60)->nullable();
            $t->string('record_label', 40)->default('Student');
            $t->boolean('is_default')->default(false);
            $t->timestamps();
        });

        Schema::create('stages', function (Blueprint $t) {
            $t->id();
            $t->foreignId('pipeline_id')->constrained('pipelines')->cascadeOnDelete();
            $t->string('name', 80);
            $t->text('description')->nullable();
            $t->string('stage_type', 20); // OPEN | CLOSED_WON | CLOSED_LOST
            $t->integer('display_order');
            $t->string('color', 7)->nullable();
            $t->timestamps();
            $t->unique(['pipeline_id', 'name']);
            $t->index(['pipeline_id', 'display_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stages');
        Schema::dropIfExists('pipelines');
    }
};
