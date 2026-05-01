<?php

namespace App\Filament\Resources\Rank;

use App\Filament\Resources\Rank\Concerns\RestrictsToRankRoles;
use App\Filament\Resources\Rank\CutoffResource\Pages;
use App\Models\Rank\AdmissionProcess;
use App\Models\Rank\Branch;
use App\Models\Rank\Course;
use App\Models\Rank\Cutoff;
use App\Models\Rank\Institute;
use App\Models\Rank\QualifyingExam;
use App\Models\Rank\University;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class CutoffResource extends Resource
{
    use RestrictsToRankRoles;

    protected static ?string $model = Cutoff::class;

    protected static ?string $navigationGroup = 'Rank Predictor';

    protected static ?string $navigationIcon = 'heroicon-o-table-cells';

    protected static ?int $navigationSort = 16;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Select::make('university_id')->label('University')
                ->options(University::pluck('name', 'id'))->required()->searchable()->reactive(),
            Select::make('course_id')->label('Course')
                ->options(fn ($get) => Course::where('university_id', $get('university_id'))->pluck('name', 'id'))
                ->required()->searchable()->reactive(),
            Select::make('qualifying_exam_id')->label('Qualifying Exam')
                ->options(QualifyingExam::pluck('name', 'id'))->required()->searchable(),
            Select::make('admission_process_id')->label('Admission Process')
                ->options(AdmissionProcess::pluck('name', 'id'))->required()->searchable(),
            TextInput::make('year')->numeric()->required()->minValue(2000)->maxValue(2100),
            Select::make('round')->options(['1' => 'Round 1', '2' => 'Round 2', '3' => 'Round 3', 'sliding' => 'Sliding'])->required(),
            Select::make('institute_id')->label('Institute')
                ->options(fn ($get) => Institute::where('university_id', $get('university_id'))->pluck('name', 'id'))
                ->required()->searchable(),
            Select::make('branch_id')->label('Branch')
                ->options(fn ($get) => Branch::where('course_id', $get('course_id'))->pluck('name', 'id'))
                ->required()->searchable(),
            Select::make('shift')->options(['I' => 'Shift I', 'II' => 'Shift II'])->placeholder('No shift'),
            Select::make('region')->options(['delhi' => 'Delhi', 'outside_delhi' => 'Outside Delhi'])->required(),
            TextInput::make('min_rank')->numeric()->required()->minValue(1),
            TextInput::make('max_rank')->numeric()->required()->minValue(1),
            Select::make('source')->options(['official' => 'Official', 'predicted' => 'Predicted', 'validated' => 'Validated'])->default('official')->required(),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('year')->sortable(),
                TextColumn::make('round')->badge()->sortable(),
                TextColumn::make('university.code')->label('Univ')->badge()->color('gray'),
                TextColumn::make('course.code')->label('Course')->badge()->color('gray'),
                TextColumn::make('qualifyingExam.code')->label('Exam')->badge()->color('info')->toggleable(),
                TextColumn::make('admissionProcess.code')->label('Process')->badge()->color('warning')->toggleable(),
                TextColumn::make('institute.name')->label('Institute')->limit(35)->searchable()->wrap(),
                TextColumn::make('branch.name')->label('Branch')->limit(30)->searchable()->wrap(),
                TextColumn::make('shift')->badge()->toggleable(),
                TextColumn::make('region')->badge()->color(fn ($state) => $state === 'delhi' ? 'success' : 'primary'),
                TextColumn::make('min_rank')->numeric()->sortable(),
                TextColumn::make('max_rank')->numeric()->sortable(),
                TextColumn::make('source')->badge()->toggleable(),
            ])
            ->filters([
                SelectFilter::make('year')->options(fn () => Cutoff::distinct()->orderBy('year', 'desc')->pluck('year', 'year')->toArray()),
                SelectFilter::make('round')->options(['1' => 'Round 1', '2' => 'Round 2', '3' => 'Round 3', 'sliding' => 'Sliding']),
                SelectFilter::make('region')->options(['delhi' => 'Delhi', 'outside_delhi' => 'Outside Delhi']),
                SelectFilter::make('university_id')->label('University')->options(University::pluck('name', 'id')),
                SelectFilter::make('course_id')->label('Course')->options(Course::pluck('name', 'id')),
                SelectFilter::make('qualifying_exam_id')->label('Exam')->options(QualifyingExam::pluck('name', 'id')),
                SelectFilter::make('admission_process_id')->label('Process')->options(AdmissionProcess::pluck('name', 'id')),
            ])
            ->actions([EditAction::make(), DeleteAction::make()])
            ->defaultSort('year', 'desc')
            ->defaultPaginationPageOption(25);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCutoffs::route('/'),
            'paste' => Pages\BulkPasteCutoffs::route('/paste'),
        ];
    }
}
