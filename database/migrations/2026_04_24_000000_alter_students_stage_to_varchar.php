<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (config('database.default') === 'sqlite') {
            // SQLite already stores enum as TEXT — nothing to alter.
            return;
        }
        DB::statement('ALTER TABLE students MODIFY stage VARCHAR(60) NOT NULL DEFAULT "Lead Captured"');
    }

    public function down(): void
    {
        if (config('database.default') === 'sqlite') {
            return;
        }
        DB::statement(<<<'SQL'
            ALTER TABLE students MODIFY stage ENUM(
                'Lead Captured','Meeting Scheduled','Meeting Done','Onboarded',
                'University Registration','Counselling In Progress','Seat Allotted',
                'Full Payment Received','Admission Confirmed','Closed'
            ) NOT NULL DEFAULT 'Lead Captured'
        SQL);
    }
};
