<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('book_entry_payments', function (Blueprint $t) {
            $t->string('source')->nullable()->after('mode');
        });
    }

    public function down(): void
    {
        Schema::table('book_entry_payments', function (Blueprint $t) {
            $t->dropColumn('source');
        });
    }
};
