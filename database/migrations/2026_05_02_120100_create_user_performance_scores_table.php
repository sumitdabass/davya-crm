<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('user_performance_scores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('period_start');
            $table->date('period_end');
            $table->unsignedTinyInteger('score');
            $table->string('tier', 20);
            $table->json('signal_breakdown');
            $table->json('team_max_snapshot');
            $table->timestamp('calculated_at');
            $table->timestamps();

            $table->unique(['user_id', 'period_start']);
            $table->index(['period_start', 'score']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_performance_scores');
    }
};
