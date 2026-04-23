<?php
namespace App\StudentFields;

use App\Models\StudentField;
use App\Models\StudentFieldValue;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;

class DynamicTableColumns
{
    /** @return array<int, \Filament\Tables\Columns\Column> */
    public function build(): array
    {
        $columns = [];
        $fields = StudentField::active()->custom()->where('show_in_table', true)->orderBy('position')->get();

        foreach ($fields as $field) {
            $columns[] = match ($field->type) {
                'checkbox' => IconColumn::make("custom_{$field->key}")
                    ->label($field->label)
                    ->boolean()
                    ->getStateUsing(fn ($record) => $this->lookupBool($record, $field)),
                'multiselect' => TextColumn::make("custom_{$field->key}")
                    ->label($field->label)
                    ->getStateUsing(fn ($record) => implode(', ', $this->lookupJson($record, $field) ?: [])),
                'dropdown' => TextColumn::make("custom_{$field->key}")
                    ->label($field->label)
                    ->badge()
                    ->getStateUsing(fn ($record) => $this->dropdownLabel($field, $this->lookupText($record, $field))),
                'number' => TextColumn::make("custom_{$field->key}")
                    ->label($field->label)
                    ->numeric()
                    ->getStateUsing(fn ($record) => $this->lookupNumber($record, $field)),
                'date' => TextColumn::make("custom_{$field->key}")
                    ->label($field->label)
                    ->date()
                    ->getStateUsing(fn ($record) => $this->lookupDate($record, $field)),
                default => TextColumn::make("custom_{$field->key}")
                    ->label($field->label)
                    ->getStateUsing(fn ($record) => $this->lookupText($record, $field)),
            };
        }
        return $columns;
    }

    private function value($record, StudentField $field): ?StudentFieldValue
    {
        return StudentFieldValue::where(['student_id' => $record->id, 'student_field_id' => $field->id])->first();
    }
    private function lookupText($record, StudentField $field): ?string { return $this->value($record, $field)?->value_text; }
    private function lookupNumber($record, StudentField $field): ?float { $v = $this->value($record, $field); return $v?->value_number === null ? null : (float) $v->value_number; }
    private function lookupDate($record, StudentField $field): ?string { return $this->value($record, $field)?->value_date?->toDateString(); }
    private function lookupBool($record, StudentField $field): bool { return $this->value($record, $field)?->value_text === '1'; }
    private function lookupJson($record, StudentField $field): ?array { return $this->value($record, $field)?->value_json; }

    private function dropdownLabel(StudentField $field, ?string $value): ?string
    {
        if ($value === null) return null;
        foreach ($field->options ?? [] as $o) {
            if (($o['value'] ?? null) === $value) return $o['label'] ?? $value;
        }
        return $value . ' (removed)';
    }
}
