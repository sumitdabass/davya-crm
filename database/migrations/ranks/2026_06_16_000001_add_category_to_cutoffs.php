<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $connection = 'ranks';

    public function up(): void
    {
        Schema::connection($this->connection)->table('cutoffs', function (Blueprint $table) {
            $table->string('category', 16)->nullable()->after('region');
            $table->string('sub_category', 24)->nullable()->after('category');
        });

        DB::connection($this->connection)->statement(
            "ALTER TABLE cutoffs MODIFY COLUMN round ENUM('1','2','3','4','5','sliding') NOT NULL"
        );

        // 3. Rebuild the unique index to include the new dimensions.
        Schema::connection($this->connection)->table('cutoffs', function (Blueprint $table) {
            $table->dropForeign('cutoffs_university_id_foreign');
            $table->dropUnique('cutoffs_unique');
            $table->unique(
                ['university_id', 'course_id', 'qualifying_exam_id', 'admission_process_id',
                 'year', 'round', 'institute_id', 'branch_id', 'shift', 'region',
                 'category', 'sub_category'],
                'cutoffs_unique'
            );
            $table->foreign('university_id')->references('id')->on('universities')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->table('cutoffs', function (Blueprint $table) {
            $table->dropForeign('cutoffs_university_id_foreign');
            $table->dropUnique('cutoffs_unique');
            $table->dropColumn(['category', 'sub_category']);
            $table->unique(
                ['university_id', 'course_id', 'qualifying_exam_id', 'admission_process_id',
                 'year', 'round', 'institute_id', 'branch_id', 'shift', 'region'],
                'cutoffs_unique'
            );
            $table->foreign('university_id')->references('id')->on('universities')->cascadeOnDelete();
        });
        DB::connection($this->connection)->statement(
            "ALTER TABLE cutoffs MODIFY COLUMN round ENUM('1','2','3','sliding') NOT NULL"
        );
    }
};
