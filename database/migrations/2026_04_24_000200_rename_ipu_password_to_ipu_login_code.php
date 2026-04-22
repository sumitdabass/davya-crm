<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Rename the column first so we can write plain values back to it.
        Schema::table('students', function (Blueprint $table) {
            $table->renameColumn('ipu_password', 'ipu_login_code');
        });

        // Decrypt every non-null value in place. Rows that fail to decrypt are
        // logged and left as-is (operator handles manually).
        DB::table('students')
            ->whereNotNull('ipu_login_code')
            ->orderBy('id')
            ->chunkById(200, function ($rows): void {
                foreach ($rows as $row) {
                    try {
                        $plain = decrypt($row->ipu_login_code);
                        DB::table('students')->where('id', $row->id)->update(['ipu_login_code' => $plain]);
                    } catch (\Throwable $e) {
                        Log::warning('ipu_login_code decrypt failed', [
                            'student_id' => $row->id,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            });
    }

    public function down(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->renameColumn('ipu_login_code', 'ipu_password');
        });
    }
};
