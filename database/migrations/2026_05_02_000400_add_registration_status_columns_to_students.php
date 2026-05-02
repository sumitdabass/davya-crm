<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->string('registration_status', 60)->default('pending')->after('is_ipu_registered');
            $table->string('counselling_registration_status', 60)->default('pending')->after('registration_status');
        });

        // Carry forward what we already know from the legacy is_ipu_registered toggle.
        \DB::statement("UPDATE students SET registration_status = 'registration_done' WHERE is_ipu_registered = 1");

        // Pin the default for seat_allotment_fee_status (column was created
        // nullable in 2026_05_02_000200; backfill blanks with 'not_allotted').
        \DB::statement("UPDATE students SET seat_allotment_fee_status = 'not_allotted' WHERE seat_allotment_fee_status IS NULL");
    }

    public function down(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->dropColumn(['registration_status', 'counselling_registration_status']);
        });
    }
};
