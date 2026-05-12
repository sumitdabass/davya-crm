<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('book_entries', function (Blueprint $t) {
            $t->decimal('emi_amount', 14, 2)->nullable()->after('interest_rate');
            $t->unsignedSmallInteger('tenure_months')->nullable()->after('emi_amount');
        });
    }

    public function down(): void
    {
        Schema::table('book_entries', function (Blueprint $t) {
            $t->dropColumn(['emi_amount', 'tenure_months']);
        });
    }
};
