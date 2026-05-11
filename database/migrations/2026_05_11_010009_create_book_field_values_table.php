<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('book_field_values', function (Blueprint $t) {
            $t->id();
            $t->foreignId('entry_id')->constrained('book_entries')->cascadeOnDelete();
            $t->foreignId('field_id')->constrained('book_fields')->cascadeOnDelete();
            // value_text: 191 chars on MySQL (utf8mb4 key length), TEXT elsewhere
            if (config('database.default') === 'mysql') {
                $t->string('value_text', 191)->nullable();
            } else {
                $t->text('value_text')->nullable();
            }
            $t->decimal('value_number', 18, 4)->nullable();
            $t->date('value_date')->nullable();
            $t->json('value_json')->nullable();
            $t->foreignId('value_attachment_id')->nullable();
            $t->timestamps();
            $t->unique(['entry_id', 'field_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('book_field_values');
    }
};
