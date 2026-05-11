<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('book_assets', function (Blueprint $t) {
            $t->id();
            $t->foreignId('entry_id')->unique()->constrained('book_entries')->cascadeOnDelete();
            $t->decimal('original_value', 14, 2);
            $t->decimal('dep_percent', 5, 2);
            $t->unsignedInteger('dep_years');
            $t->date('dep_started_at');
            $t->enum('method', ['straight_line', 'wdv'])->default('straight_line');
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('book_assets');
    }
};
