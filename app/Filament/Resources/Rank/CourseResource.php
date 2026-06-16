<?php

namespace App\Filament\Resources\Rank;

use App\Filament\Resources\Rank\Concerns\ScopesToRankDataset;
use App\Filament\Resources\Rank\CourseResource\Pages;
use App\Models\Rank\Course;
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
use Illuminate\Database\Eloquent\Builder;

class CourseResource extends Resource
{
    use ScopesToRankDataset;

    protected static ?string $model = Course::class;

    protected static function scopeToRankUniversityCodes(Builder $query, array $codes): Builder
    {
        return $query->whereHas('university', fn ($q) => $q->whereIn('code', $codes));
    }

    protected static ?string $navigationGroup = 'Rank Predictor';

    protected static ?string $navigationIcon = 'heroicon-o-book-open';

    protected static ?int $navigationSort = 11;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Select::make('university_id')
                ->label('University')
                ->options(University::pluck('name', 'id'))
                ->required()
                ->searchable(),
            TextInput::make('name')->required()->placeholder('B.Tech'),
            TextInput::make('code')->maxLength(32)->placeholder('BTECH'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('university.name')->label('University')->sortable()->searchable(),
                TextColumn::make('name')->sortable()->searchable(),
                TextColumn::make('code')->badge(),
                TextColumn::make('branches_count')->counts('branches')->label('Branches'),
            ])
            ->filters([
                SelectFilter::make('university_id')
                    ->label('University')
                    ->options(University::pluck('name', 'id')),
            ])
            ->actions([EditAction::make(), DeleteAction::make()])
            ->defaultSort('name');
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListCourses::route('/')];
    }
}
