<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('lead_import_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('source', 32);
            $table->unsignedInteger('row_count')->default(0);
            $table->unsignedInteger('created_count')->default(0);
            $table->unsignedInteger('merged_count')->default(0);
            $table->unsignedInteger('flagged_count')->default(0);
            $table->unsignedInteger('rejected_count')->default(0);
            $table->string('rejections_csv_path', 255)->nullable();
            $table->timestamps();
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_import_batches');
    }
};
