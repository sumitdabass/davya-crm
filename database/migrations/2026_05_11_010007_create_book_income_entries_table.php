<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('book_income_entries', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained('book_companies')->cascadeOnDelete();
            $t->foreignId('fiscal_year_id')->constrained('book_fiscal_years')->cascadeOnDelete();
            $t->date('occurred_on');
            $t->string('source');
            $t->decimal('amount', 14, 2);
            $t->text('notes')->nullable();
            $t->timestamps();
            $t->softDeletes();
            $t->index(['company_id', 'fiscal_year_id', 'occurred_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('book_income_entries');
    }
};
