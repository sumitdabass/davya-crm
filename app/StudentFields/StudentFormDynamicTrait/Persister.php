<?php
namespace App\StudentFields\StudentFormDynamicTrait;

use App\Models\Student;
use App\Models\StudentField;
use App\Models\StudentFieldValue;
use Illuminate\Support\Facades\DB;

class Persister
{
    /** @param array<string, mixed> $values keyed by field key */
    public function persist(Student $student, array $values): void
    {
        $fields = StudentField::custom()->active()->whereIn('key', array_keys($values))->get()->keyBy('key');

        DB::transaction(function () use ($student, $values, $fields) {
            foreach ($values as $key => $raw) {
                $field = $fields->get($key);
                if (!$field) continue;
                $payload = ['value_text' => null, 'value_number' => null, 'value_date' => null, 'value_json' => null];
                switch ($field->type) {
                    case 'number': $payload['value_number'] = $raw === null || $raw === '' ? null : (float) $raw; break;
                    case 'date': $payload['value_date'] = $raw ?: null; break;
                    case 'multiselect': $payload['value_json'] = is_array($raw) ? array_values($raw) : null; break;
                    case 'checkbox': $payload['value_text'] = $raw ? '1' : '0'; break;
                    default: $payload['value_text'] = $raw === '' ? null : (string) $raw; break;
                }
                StudentFieldValue::updateOrCreate(
                    ['student_id' => $student->id, 'student_field_id' => $field->id],
                    $payload
                );
            }
        });
    }
}
