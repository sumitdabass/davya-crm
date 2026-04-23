<?php
namespace App\Filament\Pages;

use App\Models\StudentField;
use App\Models\StudentFieldSection;
use Filament\Pages\Page;

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
}
