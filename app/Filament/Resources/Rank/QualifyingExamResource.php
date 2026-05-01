<?php

namespace App\Filament\Resources\Rank;

use App\Filament\Resources\Rank\Concerns\RestrictsToRankRoles;
use App\Filament\Resources\Rank\QualifyingExamResource\Pages;
use App\Models\Rank\QualifyingExam;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class QualifyingExamResource extends Resource
{
    use RestrictsToRankRoles;

    protected static ?string $model = QualifyingExam::class;

    protected static ?string $navigationGroup = 'Rank Predictor';

    protected static ?string $navigationIcon = 'heroicon-o-pencil-square';

    protected static ?string $navigationLabel = 'Qualifying Exams';

    protected static ?int $navigationSort = 14;

    public static function form(Form $form): Form
    {
        return $form->schema([
            TextInput::make('name')->required()->placeholder('JEE Main')->unique(ignoreRecord: true),
            TextInput::make('code')->required()->maxLength(32)->placeholder('JEE_MAIN')->unique(ignoreRecord: true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('code')->badge()->sortable(),
            ])
            ->actions([EditAction::make(), DeleteAction::make()])
            ->defaultSort('name');
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListQualifyingExams::route('/')];
    }
}
