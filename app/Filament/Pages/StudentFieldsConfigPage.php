<?php
namespace App\Filament\Pages;

use App\Models\StudentField;
use App\Models\StudentFieldSection;
use App\Models\StudentFieldValue;
use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class StudentFieldsConfigPage extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-square-3-stack-3d';
    protected static ?string $navigationGroup = 'Settings';
    protected static ?string $navigationLabel = 'Student Fields';
    protected static ?string $title = 'Student Field Config';
    protected static ?string $slug = 'student-fields';
    protected static string $view = 'filament.pages.student-fields-config';
    protected static ?int $navigationSort = 2;

    public string $activeTab = 'live'; // 'live' | 'archived'
    public ?int $selectedSectionId = null;

    // Inline add-form state
    public string $newSectionName = '';
    public ?int $newFieldSectionId = null;
    public string $newFieldLabel = '';
    public string $newFieldType = 'text';
    public bool $newFieldRequired = false;
    public bool $newFieldShowInTable = false;
    public bool $newFieldShowInKanban = false;
    public bool $newFieldShowInImport = false;
    public string $newFieldOptionsText = ''; // one option per line, dropdown/multiselect only

    public function toggleFieldRequired(int $fieldId): void
    {
        $field = StudentField::findOrFail($fieldId);
        if ($field->key === 'phone') {
            $this->addError('required_'.$fieldId, 'Phone is always required.');
            return;
        }
        $field->update(['is_required' => !$field->is_required]);
    }

    // Inline edit state
    public ?int $editingFieldId = null;
    public string $editFieldLabel = '';
    public string $editFieldOptionsText = '';

    public function startEdit(int $fieldId): void
    {
        $field = StudentField::findOrFail($fieldId);
        $this->editingFieldId = $fieldId;
        $this->editFieldLabel = $field->label;
        $opts = is_array($field->options) ? $field->options : [];
        $flat = array_map(fn ($o) => is_array($o) ? ($o['label'] ?? $o['value'] ?? '') : $o, $opts);
        $this->editFieldOptionsText = implode("\n", $flat);
        $this->resetErrorBag();
    }

    public function cancelEdit(): void
    {
        $this->editingFieldId = null;
        $this->editFieldLabel = '';
        $this->editFieldOptionsText = '';
    }

    public function saveEdit(): void
    {
        if (!$this->editingFieldId) return;
        $field = StudentField::findOrFail($this->editingFieldId);
        $data = [];
        $label = trim($this->editFieldLabel);
        if ($label === '') {
            $this->addError('editFieldLabel', 'Label required');
            return;
        }
        $data['label'] = $label;
        if (in_array($field->type, ['dropdown','multiselect'], true)) {
            $lines = array_values(array_filter(array_map('trim', preg_split('/\r?\n/', $this->editFieldOptionsText))));
            if (empty($lines)) {
                $this->addError('editFieldOptionsText', 'At least one option required');
                return;
            }
            $data['options'] = array_map(fn ($v) => ['value' => $v, 'label' => $v], $lines);
        }
        $this->updateField($this->editingFieldId, $data);
        if ($this->getErrorBag()->isEmpty()) {
            $this->cancelEdit();
        }
    }

    public function moveFieldUp(int $fieldId): void
    {
        $field = StudentField::findOrFail($fieldId);
        $above = StudentField::where('section_id', $field->section_id)
            ->where('position', '<', $field->position)
            ->orderBy('position', 'desc')
            ->first();
        if (!$above) return;
        DB::transaction(function () use ($field, $above) {
            $fp = $field->position; $ap = $above->position;
            $field->update(['position' => $ap]);
            $above->update(['position' => $fp]);
        });
    }

    public function moveFieldDown(int $fieldId): void
    {
        $field = StudentField::findOrFail($fieldId);
        $below = StudentField::where('section_id', $field->section_id)
            ->where('position', '>', $field->position)
            ->orderBy('position', 'asc')
            ->first();
        if (!$below) return;
        DB::transaction(function () use ($field, $below) {
            $fp = $field->position; $bp = $below->position;
            $field->update(['position' => $bp]);
            $below->update(['position' => $fp]);
        });
    }

    public function submitNewSection(): void
    {
        $name = trim($this->newSectionName);
        if ($name === '') {
            $this->addError('newSectionName', 'Name required');
            return;
        }
        $this->createSection($name);
        $this->newSectionName = '';
        $this->selectedSectionId = StudentFieldSection::orderBy('position', 'desc')->value('id');
    }

    public function submitNewField(): void
    {
        $targetSectionId = $this->newFieldSectionId ?: $this->selectedSectionId;
        if (!$targetSectionId) {
            $this->addError('newFieldSectionId', 'Pick a section');
            return;
        }
        $options = null;
        if (in_array($this->newFieldType, ['dropdown','multiselect'], true)) {
            $lines = array_values(array_filter(array_map('trim', preg_split('/\r?\n/', $this->newFieldOptionsText))));
            if (empty($lines)) {
                $this->addError('newFieldOptionsText', 'At least one option required');
                return;
            }
            $options = array_map(fn ($v) => ['value' => $v, 'label' => $v], $lines);
        }
        $this->createField([
            'label' => $this->newFieldLabel,
            'type' => $this->newFieldType,
            'section_id' => $targetSectionId,
            'is_required' => $this->newFieldRequired,
            'options' => $options ?? [],
            'show_in_table' => $this->newFieldShowInTable,
            'show_in_kanban' => $this->newFieldShowInKanban,
            'show_in_import' => $this->newFieldShowInImport,
        ]);
        if (!$this->getErrorBag()->isEmpty()) return;
        $this->newFieldSectionId = null;
        $this->newFieldLabel = '';
        $this->newFieldType = 'text';
        $this->newFieldRequired = false;
        $this->newFieldShowInTable = false;
        $this->newFieldShowInKanban = false;
        $this->newFieldShowInImport = false;
        $this->newFieldOptionsText = '';
    }

    public static function canAccess(): bool
    {
        return auth()->check() && auth()->user()->hasRole('admin');
    }

    public function mount(): void
    {
        $this->selectedSectionId = StudentFieldSection::orderBy('position')->value('id');
    }

    public function sections()
    {
        return StudentFieldSection::orderBy('position')->get();
    }

    public function fieldsForSelectedSection()
    {
        if (!$this->selectedSectionId) return collect();
        return StudentField::active()->where('section_id', $this->selectedSectionId)->orderBy('position')->get();
    }

    public function archivedFields()
    {
        return StudentField::archived()->orderBy('archived_at', 'desc')->get();
    }

    public function createSection(string $name): void
    {
        $name = trim($name);
        if ($name === '') return;
        $position = (int) StudentFieldSection::max('position') + 1;
        StudentFieldSection::create(['name' => $name, 'position' => $position]);
    }

    public function renameSection(int $id, string $name): void
    {
        $name = trim($name);
        if ($name === '') return;
        StudentFieldSection::where('id', $id)->update(['name' => $name]);
    }

    public function reorderSections(array $orderedIds): void
    {
        DB::transaction(function () use ($orderedIds) {
            foreach ($orderedIds as $i => $id) {
                StudentFieldSection::where('id', $id)->update(['position' => $i]);
            }
        });
    }

    public function deleteSection(int $id): void
    {
        $hasFields = StudentField::where('section_id', $id)->exists();
        if ($hasFields) {
            $this->addError('transfer_target', 'Section has fields — pick a transfer target.');
            return;
        }
        StudentFieldSection::where('id', $id)->delete();
    }

    public function deleteSectionWithTransfer(int $sourceId, int $destinationId): void
    {
        if ($sourceId === $destinationId) {
            $this->addError('transfer_target', 'Cannot transfer to the same section.');
            return;
        }
        DB::transaction(function () use ($sourceId, $destinationId) {
            $maxPos = (int) StudentField::where('section_id', $destinationId)->max('position');
            $i = $maxPos + 1;
            foreach (StudentField::where('section_id', $sourceId)->orderBy('position')->get() as $field) {
                $field->update(['section_id' => $destinationId, 'position' => $i++]);
            }
            StudentFieldSection::where('id', $sourceId)->delete();
        });
    }

    public function createField(array $data): void
    {
        $label = trim($data['label'] ?? '');
        if ($label === '') {
            $this->addError('label', 'Label required'); return;
        }
        $key = $this->generateUniqueKey($label);
        $type = $data['type'] ?? 'text';
        $allowed = ['text','textarea','number','date','email','dropdown','checkbox','multiselect'];
        if (!in_array($type, $allowed, true)) {
            $this->addError('type', 'Invalid type'); return;
        }
        $sectionId = (int) ($data['section_id'] ?? 0);
        if (!$sectionId || !StudentFieldSection::find($sectionId)) {
            $this->addError('section_id', 'Section required'); return;
        }
        $position = (int) StudentField::where('section_id', $sectionId)->max('position') + 1;

        StudentField::create([
            'section_id' => $sectionId,
            'key' => $key,
            'label' => $label,
            'type' => $type,
            'is_required' => (bool) ($data['is_required'] ?? false),
            'is_built_in' => false,
            'options' => in_array($type, ['dropdown','multiselect'], true) ? ($data['options'] ?? []) : null,
            'show_in_table' => (bool) ($data['show_in_table'] ?? false),
            'show_in_kanban' => (bool) ($data['show_in_kanban'] ?? false),
            'show_in_import' => (bool) ($data['show_in_import'] ?? false),
            'position' => $position,
        ]);
    }

    public function updateField(int $id, array $data): void
    {
        $field = StudentField::findOrFail($id);
        $update = [];
        if (isset($data['label']) && trim($data['label']) !== '') $update['label'] = trim($data['label']);

        // Built-in lock rules — see Task 9
        $update['is_required'] = (bool) ($data['is_required'] ?? $field->is_required);
        if ($field->key === 'phone') $update['is_required'] = true;

        foreach (['show_in_table','show_in_kanban','show_in_import'] as $f) {
            if (array_key_exists($f, $data)) $update[$f] = (bool) $data[$f];
        }
        if (isset($data['options']) && in_array($field->type, ['dropdown','multiselect'], true)) {
            $update['options'] = $data['options'];
        }
        $field->update($update);
    }

    public function reorderFields(int $sectionId, array $orderedIds): void
    {
        DB::transaction(function () use ($sectionId, $orderedIds) {
            foreach ($orderedIds as $i => $id) {
                StudentField::where('id', $id)->where('section_id', $sectionId)->update(['position' => $i]);
            }
        });
    }

    public function archiveField(int $id): void
    {
        $field = StudentField::findOrFail($id);
        if ($field->is_built_in) {
            $this->addError('archive', 'Built-in fields cannot be archived.');
            return;
        }
        $field->update(['archived_at' => now()]);
    }

    public function restoreField(int $id): void
    {
        $field = StudentField::findOrFail($id);
        if ($field->section_id && !StudentFieldSection::find($field->section_id)) {
            $field->section_id = StudentFieldSection::orderBy('position')->value('id');
        }
        $field->archived_at = null;
        $field->save();
    }

    public function hardDeleteField(int $id, string $confirm): void
    {
        $field = StudentField::findOrFail($id);
        if ($field->is_built_in) {
            $this->addError('archive', 'Built-in fields cannot be deleted.');
            return;
        }
        if ($confirm !== 'DELETE') {
            $this->addError('confirm', 'Type DELETE to confirm.');
            return;
        }
        DB::transaction(function () use ($field) {
            StudentFieldValue::where('student_field_id', $field->id)->delete();
            $field->delete();
        });
    }

    private function generateUniqueKey(string $label): string
    {
        // Replace common symbols before slugging.
        $base = str_replace('%', 'percent', $label);
        $base = Str::slug($base, '_');
        if ($base === '') $base = 'field';
        $key = $base; $i = 2;
        while (StudentField::where('key', $key)->exists()) { $key = $base . '_' . $i++; }
        return $key;
    }
}
