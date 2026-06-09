<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `students.plan` was created as enum('Online','Offline','All'), but the plan
 * dropdown options were changed to 'Sitting' / 'Counselling Online' /
 * 'Counselling Offline' (migration ..._000200) without widening the column.
 * Every student insert with a new plan value then 500s with
 * "1265 Data truncated for column 'plan'". Widen to a plain string so the
 * column behaves like every other config-driven dropdown; existing rows
 * (Online/Offline/All) are preserved untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->string('plan', 50)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->enum('plan', ['Online', 'Offline', 'All'])->nullable()->change();
        });
    }
};
