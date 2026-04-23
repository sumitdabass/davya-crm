<?php
namespace App\Filament\Pages;

use App\Models\StudentField;
use App\Models\StudentFieldSection;
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
        if (!$field->is_built_in && isset($data['options']) && in_array($field->type, ['dropdown','multiselect'], true)) {
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
