<?php

namespace App\Filament\Resources\Rank;

use App\Filament\Resources\Rank\Concerns\RestrictsToRankRoles;
use App\Filament\Resources\Rank\UniversityResource\Pages;
use App\Models\Rank\University;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class UniversityResource extends Resource
{
    use RestrictsToRankRoles;

    protected static ?string $model = University::class;

    protected static ?string $navigationGroup = 'Rank Predictor';

    protected static ?string $navigationIcon = 'heroicon-o-academic-cap';

    protected static ?int $navigationSort = 10;

    public static function form(Form $form): Form
    {
        return $form->schema([
            TextInput::make('name')->required()->unique(ignoreRecord: true)->columnSpanFull(),
            TextInput::make('code')->required()->unique(ignoreRecord: true)->maxLength(32),
            TextInput::make('official_website')->url()->prefix('https://')->placeholder('ipu.ac.in'),
            TextInput::make('country')->default('India'),
            TextInput::make('state')->placeholder('Delhi'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('code')->badge()->sortable(),
                TextColumn::make('state')->sortable()->toggleable(),
                TextColumn::make('country')->sortable()->toggleable(),
                TextColumn::make('official_website')->url(fn ($r) => $r->official_website, true)->limit(30)->toggleable(),
                TextColumn::make('institutes_count')->counts('institutes')->label('Institutes'),
                TextColumn::make('courses_count')->counts('courses')->label('Courses'),
            ])
            ->actions([EditAction::make(), DeleteAction::make()])
            ->defaultSort('name');
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListUniversities::route('/')];
    }
}
