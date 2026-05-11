<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('book_entries', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained('book_companies')->cascadeOnDelete();
            $t->foreignId('fiscal_year_id')->constrained('book_fiscal_years')->cascadeOnDelete();
            $t->foreignId('section_id')->constrained('book_sections')->cascadeOnDelete();
            $t->string('title');
            $t->decimal('salary_amount', 14, 2)->default(0);
            $t->decimal('loan_amount', 14, 2)->default(0);
            $t->text('notes')->nullable();
            $t->unsignedInteger('sort_order')->default(0);
            $t->timestamps();
            $t->softDeletes();
            $t->index(['company_id', 'fiscal_year_id', 'section_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('book_entries');
    }
};
