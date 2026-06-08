<?php

use App\Models\StudentField;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        StudentField::where('key', 'plan')->update([
            'options' => ['Sitting', 'Counselling Online', 'Counselling Offline'],
        ]);
    }

    public function down(): void
    {
        StudentField::where('key', 'plan')->update([
            'options' => ['Online', 'Offline', 'All'],
        ]);
    }
};
