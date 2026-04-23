<?php
namespace App\Filament\Pages;

use App\Models\StudentField;
use App\Models\StudentFieldSection;
use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;

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
}
