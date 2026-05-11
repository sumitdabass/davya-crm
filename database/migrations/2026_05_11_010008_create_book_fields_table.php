<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('book_fields', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained('book_companies')->cascadeOnDelete();
            $t->foreignId('section_id')->nullable()
                ->constrained('book_sections')->cascadeOnDelete();
            $t->string('key');
            $t->string('label');
            $t->string('type', 32);
            $t->json('options_json')->nullable();
            $t->boolean('is_required')->default(false);
            $t->boolean('show_in_table')->default(false);
            $t->boolean('is_built_in')->default(false);
            $t->unsignedInteger('sort_order')->default(0);
            $t->timestamp('archived_at')->nullable();
            $t->timestamps();
            $t->unique(['company_id', 'section_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('book_fields');
    }
};
