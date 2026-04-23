<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('student_field_values', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->foreignId('student_field_id')->constrained('student_fields')->cascadeOnDelete();
            $table->text('value_text')->nullable();
            $table->decimal('value_number', 20, 4)->nullable();
            $table->date('value_date')->nullable();
            $table->json('value_json')->nullable();
            $table->timestamps();
            $table->unique(['student_id', 'student_field_id']);
            // value_number / value_date can be indexed normally on every backend.
            $table->index(['student_field_id', 'value_number'], 'sfv_field_number_idx');
            $table->index(['student_field_id', 'value_date'], 'sfv_field_date_idx');
        });

        // value_text is TEXT — MySQL needs a key-length prefix; SQLite tolerates a plain
        // index. Apply the right shape per driver so prod migrations don't blow up.
        $driver = DB::connection()->getDriverName();
        if ($driver === 'mysql') {
            DB::statement('CREATE INDEX sfv_field_text_idx ON student_field_values (student_field_id, value_text(191))');
        } else {
            Schema::table('student_field_values', function (Blueprint $table) {
                $table->index(['student_field_id', 'value_text'], 'sfv_field_text_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('student_field_values');
    }
};
