<?php
namespace App\StudentFields;

use App\Models\StudentField;
use Filament\Forms\Components\Component;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;

class FieldRenderer
{
    public function render(StudentField $field, ?string $statePath = null): Component
    {
        $name = $statePath ?? "custom_fields.{$field->key}";
        $component = match ($field->type) {
            'text' => TextInput::make($name)->maxLength(255),
            'textarea' => Textarea::make($name)->rows(3),
            'number' => TextInput::make($name)->numeric(),
            'date' => DatePicker::make($name)->native(false),
            'email' => TextInput::make($name)->email()->maxLength(255),
            'dropdown' => Select::make($name)->options($this->options($field)),
            'checkbox' => Toggle::make($name),
            'multiselect' => Select::make($name)->multiple()->options($this->options($field)),
            default => TextInput::make($name),
        };

        $component->label($field->label);

        if ($field->is_required || $field->key === 'phone') {
            $component->required();
        }

        return $component;
    }

    /** @return array<string, string> */
    private function options(StudentField $field): array
    {
        $opts = $field->options ?? [];
        return collect($opts)->mapWithKeys(fn ($o) => [$o['value'] => $o['label']])->all();
    }
}
