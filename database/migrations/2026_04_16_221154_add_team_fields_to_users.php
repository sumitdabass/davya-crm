<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('team_head_id')->nullable()->after('email')->constrained('users')->nullOnDelete();
            $table->boolean('is_freelancer')->default(false)->after('team_head_id');
            $table->boolean('is_active')->default(true)->after('is_freelancer');
            $table->boolean('must_change_password')->default(false)->after('password');
            $table->index('team_head_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['team_head_id']);
            $table->dropIndex(['team_head_id']);
            $table->dropColumn(['team_head_id', 'is_freelancer', 'is_active', 'must_change_password']);
        });
    }
};
