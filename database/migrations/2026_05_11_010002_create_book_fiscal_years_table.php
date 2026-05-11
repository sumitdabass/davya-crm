<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('book_fiscal_years', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained('book_companies')->cascadeOnDelete();
            $t->date('start_date');
            $t->date('end_date');
            $t->string('label', 16);
            $t->boolean('is_closed')->default(false);
            $t->json('closing_summary_json')->nullable();
            $t->timestamps();
            $t->softDeletes();
            $t->unique(['company_id', 'label']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('book_fiscal_years');
    }
};
