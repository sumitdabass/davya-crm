<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('book_sections', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained('book_companies')->cascadeOnDelete();
            $t->string('slug');
            $t->string('name');
            $t->enum('kind', ['generic', 'asset'])->default('generic');
            $t->unsignedInteger('sort_order')->default(0);
            $t->string('icon')->nullable();
            $t->json('visible_money_columns')->nullable();
            $t->timestamps();
            $t->softDeletes();
            $t->unique(['company_id', 'slug']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('book_sections');
    }
};
