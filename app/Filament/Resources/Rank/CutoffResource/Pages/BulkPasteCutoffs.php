<?php

namespace App\Filament\Resources\Rank\CutoffResource\Pages;

use App\Filament\Resources\Rank\CutoffResource;
use App\Models\Rank\AdmissionProcess;
use App\Models\Rank\Course;
use App\Models\Rank\QualifyingExam;
use App\Models\Rank\University;
use App\Services\Rank\RankBulkParser;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;

class BulkPasteCutoffs extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string $resource = CutoffResource::class;

    protected static string $view = 'filament.pages.rank.bulk-paste-cutoffs';

    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill([
            'year' => (int) date('Y'),
            'round' => '1',
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Select::make('university_id')->label('University')
                    ->options(University::pluck('name', 'id'))->required()->searchable()->reactive(),
                Select::make('course_id')->label('Course')
                    ->options(fn ($get) => Course::where('university_id', $get('university_id'))->pluck('name', 'id'))
                    ->required()->searchable(),
                Select::make('qualifying_exam_id')->label('Qualifying Exam')
                    ->options(QualifyingExam::pluck('name', 'id'))->required()->searchable(),
                Select::make('admission_process_id')->label('Admission Process')
                    ->options(AdmissionProcess::pluck('name', 'id'))->required()->searchable(),
                TextInput::make('year')->numeric()->required()->minValue(2000)->maxValue(2100),
                Select::make('round')->options([
                    '1' => 'Round 1',
                    '2' => 'Round 2',
                    '3' => 'Round 3',
                    'sliding' => 'Sliding',
                ])->required()->reactive(),
                Textarea::make('paste')
                    ->label('Paste Excel data here')
                    ->required()
                    ->rows(20)
                    ->columnSpanFull()
                    ->helperText(fn ($get) => $get('round') === 'sliding'
                        ? 'Format: Institute<TAB>Branch<TAB>Maximum Rank (one row per branch). "Not Applicable" rows are skipped.'
                        : 'Format: Institute<TAB>Branch<TAB>Delhi cell<TAB>Outside Delhi cell. Cells use "Min Rank - X Max Rank - Y" format.'),
            ])
            ->columns(2)
            ->statePath('data');
    }

    public function submit(): void
    {
        $data = $this->form->getState();
        $parser = new RankBulkParser;
        $result = $parser->importCutoffs($data['paste'], [
            'university_id' => (int) $data['university_id'],
            'course_id' => (int) $data['course_id'],
            'qualifying_exam_id' => (int) $data['qualifying_exam_id'],
            'admission_process_id' => (int) $data['admission_process_id'],
            'year' => (int) $data['year'],
            'round' => $data['round'],
        ]);

        Notification::make()
            ->title("Imported {$result['imported']} cutoff rows · skipped {$result['skipped']}")
            ->success()
            ->send();

        $this->redirect(CutoffResource::getUrl('index'));
    }
}
