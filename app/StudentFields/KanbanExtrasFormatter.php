<?php
namespace App\StudentFields;

use App\Models\Student;
use App\Models\StudentField;
use App\Models\StudentFieldValue;

class KanbanExtrasFormatter
{
    public const MAX = 3;

    /** @return array<int, string> */
    public function format(Student $student): array
    {
        $fields = StudentField::active()
            ->where('show_in_kanban', true)
            ->orderBy('position')
            ->limit(self::MAX)
            ->get();

        $pairs = [];
        foreach ($fields as $field) {
            $value = StudentFieldValue::where(['student_id' => $student->id, 'student_field_id' => $field->id])->first();
            $rendered = $this->renderValue($field, $value);
            if ($rendered !== null && $rendered !== '') {
                $pairs[] = "{$field->label}: {$rendered}";
            }
        }
        return $pairs;
    }

    public function shouldWarnTooManyEnabled(): bool
    {
        return StudentField::active()->where('show_in_kanban', true)->count() > self::MAX;
    }

    private function renderValue(StudentField $field, ?StudentFieldValue $v): ?string
    {
        if ($v === null) return null;
        return match ($field->type) {
            'number' => $v->value_number === null ? null : (string) (int) $v->value_number,
            'date' => $v->value_date?->toDateString(),
            'multiselect' => is_array($v->value_json) ? implode(',', $v->value_json) : null,
            'checkbox' => $v->value_text === '1' ? 'Yes' : 'No',
            default => $v->value_text,
        };
    }
}
