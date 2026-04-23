<?php
namespace App\StudentFields;

use App\Models\Student;
use App\Models\StudentField;
use App\StudentFields\StudentFormDynamicTrait\Persister;

class ImportColumnMapper
{
    /** @return array<int, string> */
    public function templateHeaders(): array
    {
        return StudentField::active()->where('show_in_import', true)->orderBy('position')->pluck('key')->all();
    }

    /** @param array<string, mixed> $row */
    public function applyRow(Student $student, array $row): void
    {
        $fields = StudentField::active()->where('show_in_import', true)->get()->keyBy('key');
        $builtInUpdates = [];
        $customValues = [];

        foreach ($row as $col => $val) {
            $field = $fields->get($col);
            if (!$field) continue;
            if ($field->is_built_in) {
                $builtInUpdates[$field->built_in_column] = $val;
            } else {
                $customValues[$field->key] = $val;
            }
        }
        if ($builtInUpdates) $student->update($builtInUpdates);
        if ($customValues) (new Persister())->persist($student, $customValues);
    }
}
