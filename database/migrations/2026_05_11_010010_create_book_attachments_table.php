<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('book_attachments', function (Blueprint $t) {
            $t->id();
            $t->morphs('attachable'); // attachable_id + attachable_type + index
            $t->string('disk', 32)->default('gdrive');
            $t->string('path');
            $t->string('original_name');
            $t->string('mime', 128)->nullable();
            $t->unsignedBigInteger('size')->nullable();
            $t->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('uploaded_at')->useCurrent();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('book_attachments');
    }
};
