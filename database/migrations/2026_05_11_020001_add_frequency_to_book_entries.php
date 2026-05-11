<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('book_entries', function (Blueprint $t) {
            $t->string('frequency', 16)->default('one_time')->after('loan_amount');
        });
    }

    public function down(): void
    {
        Schema::table('book_entries', function (Blueprint $t) {
            $t->dropColumn('frequency');
        });
    }
};
