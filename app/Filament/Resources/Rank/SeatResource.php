<?php

namespace App\Filament\Resources\Rank;

use App\Filament\Resources\Rank\Concerns\ScopesToRankDataset;
use App\Filament\Resources\Rank\SeatResource\Pages;
use App\Models\Rank\Branch;
use App\Models\Rank\Course;
use App\Models\Rank\Institute;
use App\Models\Rank\Seat;
use App\Models\Rank\University;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class SeatResource extends Resource
{
    use ScopesToRankDataset;

    protected static ?string $model = Seat::class;

    protected static function scopeToRankUniversityCodes(Builder $query, array $codes): Builder
    {
        return $query->whereHas('university', fn ($q) => $q->whereIn('code', $codes));
    }

    protected static ?string $navigationGroup = 'Rank Predictor';

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    protected static ?int $navigationSort = 17;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Select::make('university_id')->label('University')
                ->options(University::pluck('name', 'id'))->required()->searchable()->reactive(),
            Select::make('course_id')->label('Course')
                ->options(fn ($get) => Course::where('university_id', $get('university_id'))->pluck('name', 'id'))
                ->required()->searchable()->reactive(),
            TextInput::make('year')->numeric()->required()->minValue(2000)->maxValue(2100),
            Select::make('institute_id')->label('Institute')
                ->options(fn ($get) => Institute::where('university_id', $get('university_id'))->pluck('name', 'id'))
                ->required()->searchable(),
            Select::make('branch_id')->label('Branch')
                ->options(fn ($get) => Branch::where('course_id', $get('course_id'))->pluck('name', 'id'))
                ->required()->searchable(),
            TextInput::make('seat_count')->numeric()->required()->minValue(1),
            Textarea::make('source_note')->columnSpanFull(),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('year')->sortable(),
                TextColumn::make('university.code')->label('Univ')->badge()->color('gray'),
                TextColumn::make('course.code')->label('Course')->badge()->color('gray'),
                TextColumn::make('institute.name')->label('Institute')->limit(35)->searchable()->wrap(),
                TextColumn::make('branch.name')->label('Branch')->limit(30)->searchable()->wrap(),
                TextColumn::make('seat_count')->numeric()->sortable()->label('Seats')->alignEnd(),
            ])
            ->filters([
                SelectFilter::make('year')->options(fn () => Seat::distinct()->orderBy('year', 'desc')->pluck('year', 'year')->toArray()),
                SelectFilter::make('university_id')->label('University')->options(University::pluck('name', 'id')),
                SelectFilter::make('course_id')->label('Course')->options(Course::pluck('name', 'id')),
            ])
            ->actions([EditAction::make(), DeleteAction::make()])
            ->defaultSort('year', 'desc')
            ->defaultPaginationPageOption(25);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSeats::route('/'),
            'paste' => Pages\BulkPasteSeats::route('/paste'),
        ];
    }
}
