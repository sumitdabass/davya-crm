<?php

namespace App\Filament\Resources\Rank;

use App\Filament\Resources\Rank\BranchResource\Pages;
use App\Filament\Resources\Rank\Concerns\RestrictsToRankRoles;
use App\Models\Rank\Branch;
use App\Models\Rank\Course;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class BranchResource extends Resource
{
    use RestrictsToRankRoles;

    protected static ?string $model = Branch::class;

    protected static ?string $navigationGroup = 'Rank Predictor';

    protected static ?string $navigationIcon = 'heroicon-o-cube';

    protected static ?int $navigationSort = 13;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Select::make('course_id')
                ->label('Course')
                ->options(fn () => Course::with('university')->get()->mapWithKeys(fn ($c) => [$c->id => "{$c->university->code} - {$c->name}"]))
                ->required()
                ->searchable(),
            TextInput::make('name')->required()->columnSpanFull()->placeholder('Computer Science & Engineering'),
            TextInput::make('family')->maxLength(64)->placeholder('CSE'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('course.university.code')->label('Univ')->badge(),
                TextColumn::make('course.name')->label('Course')->badge()->color('gray'),
                TextColumn::make('name')->searchable()->sortable()->limit(60)->wrap(),
                TextColumn::make('family')->badge()->color('warning')->toggleable(),
            ])
            ->filters([
                SelectFilter::make('course_id')
                    ->label('Course')
                    ->options(fn () => Course::with('university')->get()->mapWithKeys(fn ($c) => [$c->id => "{$c->university->code} - {$c->name}"])),
            ])
            ->actions([EditAction::make(), DeleteAction::make()])
            ->defaultSort('name');
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListBranches::route('/')];
    }
}
