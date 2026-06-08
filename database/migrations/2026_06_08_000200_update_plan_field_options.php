<?php

use App\Models\StudentField;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /** Match the labeled `['value' => x, 'label' => x]` shape used by every other static dropdown. */
    private function labeled(array $values): array
    {
        return array_map(fn ($v) => ['value' => $v, 'label' => $v], $values);
    }

    public function up(): void
    {
        StudentField::where('key', 'plan')->update([
            'options' => $this->labeled(['Sitting', 'Counselling Online', 'Counselling Offline']),
        ]);
    }

    public function down(): void
    {
        StudentField::where('key', 'plan')->update([
            'options' => $this->labeled(['Online', 'Offline', 'All']),
        ]);
    }
};
