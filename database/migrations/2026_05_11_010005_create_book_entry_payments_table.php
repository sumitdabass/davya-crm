<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('book_entry_payments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('entry_id')->constrained('book_entries')->cascadeOnDelete();
            $t->date('occurred_on');
            $t->decimal('amount', 14, 2);
            $t->enum('direction', ['out', 'in']);
            $t->enum('mode', ['cash', 'bank', 'upi', 'cheque', 'other']);
            $t->string('reference')->nullable();
            $t->text('notes')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->softDeletes();
            $t->index(['entry_id', 'occurred_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('book_entry_payments');
    }
};
