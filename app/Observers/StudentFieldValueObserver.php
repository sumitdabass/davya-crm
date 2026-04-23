<?php
namespace App\Observers;

use App\Models\StudentFieldValue;

class StudentFieldValueObserver
{
    public function created(StudentFieldValue $value): void
    {
        $label = $value->field?->label ?? 'field';
        $current = $this->displayValue($value);
        activity()
            ->performedOn($value)
            ->causedBy(auth()->user())
            ->log("field.{$label}: (empty) → {$current}");
    }

    public function updated(StudentFieldValue $value): void
    {
        $label = $value->field?->label ?? 'field';
        $original = [];
        foreach (['value_text','value_number','value_date','value_json'] as $col) {
            if ($value->isDirty($col)) $original[$col] = $value->getOriginal($col);
        }
        if (!$original) return;
        $oldDisplay = $this->displayFromArray($original);
        $newDisplay = $this->displayValue($value);
        activity()
            ->performedOn($value)
            ->causedBy(auth()->user())
            ->log("field.{$label}: {$oldDisplay} → {$newDisplay}");
    }

    private function displayValue(StudentFieldValue $v): string
    {
        if ($v->value_text !== null) return (string) $v->value_text;
        if ($v->value_number !== null) return (string) $v->value_number;
        if ($v->value_date !== null) return $v->value_date->toDateString();
        if ($v->value_json !== null) return json_encode($v->value_json);
        return '(empty)';
    }

    private function displayFromArray(array $a): string
    {
        if (($a['value_text'] ?? null) !== null) return (string) $a['value_text'];
        if (($a['value_number'] ?? null) !== null) return (string) $a['value_number'];
        if (($a['value_date'] ?? null) !== null) return is_string($a['value_date']) ? $a['value_date'] : (string) $a['value_date'];
        if (($a['value_json'] ?? null) !== null) return json_encode($a['value_json']);
        return '(empty)';
    }
}
