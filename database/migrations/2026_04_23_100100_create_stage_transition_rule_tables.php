<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('stage_transition_rules', function (Blueprint $t) {
            $t->id();
            $t->foreignId('pipeline_id')->constrained('pipelines')->cascadeOnDelete();
            $t->string('name', 160);
            $t->foreignId('from_stage_id')->nullable()->constrained('stages')->nullOnDelete();
            $t->foreignId('to_stage_id')->nullable()->constrained('stages')->nullOnDelete();
            $t->string('severity', 10); // HARD | SOFT
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->index(['pipeline_id', 'to_stage_id', 'is_active']);
        });

        Schema::create('stage_transition_conditions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('rule_id')->constrained('stage_transition_rules')->cascadeOnDelete();
            $t->string('condition_type', 20); // FIELD_CHECK | HAS_RELATION
            $t->string('field_or_relation', 60);
            $t->string('operator', 24);
            $t->json('value')->nullable();
            $t->integer('display_order')->default(0);
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stage_transition_conditions');
        Schema::dropIfExists('stage_transition_rules');
    }
};
