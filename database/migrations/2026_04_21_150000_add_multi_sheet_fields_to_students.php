<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->string('rank', 40)->nullable()->after('twelfth_marks');
            $table->string('state', 40)->nullable()->after('category');
            $table->string('email', 120)->nullable()->after('phone_2');
        });

        Schema::table('students', function (Blueprint $table) {
            $table->string('name', 120)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->string('name', 120)->nullable(false)->change();
        });

        Schema::table('students', function (Blueprint $table) {
            $table->dropColumn(['rank', 'state', 'email']);
        });
    }
};
