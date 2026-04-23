<?php
namespace App\StudentFields\StudentFormDynamicTrait;

use App\Models\Student;
use App\Models\StudentFieldValue;

class Hydrator
{
    /** @return array<string, mixed> keyed by field key */
    public function hydrate(Student $student): array
    {
        $out = [];
        $values = StudentFieldValue::where('student_id', $student->id)
            ->with('field')->get();

        foreach ($values as $v) {
            $field = $v->field;
            if (!$field || $field->is_built_in) continue;
            $out[$field->key] = match ($field->type) {
                'number' => $v->value_number !== null ? (float) $v->value_number : null,
                'date' => $v->value_date?->toDateString(),
                'multiselect' => $v->value_json ?? [],
                'checkbox' => $v->value_text === '1',
                default => $v->value_text,
            };
        }
        return $out;
    }
}
