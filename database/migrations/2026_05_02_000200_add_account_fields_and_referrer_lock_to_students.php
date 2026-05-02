<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->text('address')->nullable()->after('phone_2');
            $table->string('email_2', 120)->nullable()->after('email');
            $table->string('sub_category', 60)->nullable()->after('category');
            $table->string('university', 120)->nullable()->after('course');
            $table->string('seat_allotment_fee_status', 80)->nullable()->after('seat_fee_due');
            // Lead Owner (referrer_id) lock — head can edit until this is set,
            // then they need an admin to clear it.
            $table->timestamp('referrer_id_locked_at')->nullable()->after('referrer_id');
        });
    }

    public function down(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->dropColumn([
                'address', 'email_2', 'sub_category', 'university',
                'seat_allotment_fee_status', 'referrer_id_locked_at',
            ]);
        });
    }
};
