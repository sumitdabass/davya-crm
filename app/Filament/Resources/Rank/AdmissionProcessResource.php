<?php

namespace App\Filament\Resources\Rank;

use App\Filament\Resources\Rank\AdmissionProcessResource\Pages;
use App\Filament\Resources\Rank\Concerns\RestrictsToRankRoles;
use App\Models\Rank\AdmissionProcess;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class AdmissionProcessResource extends Resource
{
    use RestrictsToRankRoles;

    protected static ?string $model = AdmissionProcess::class;

    protected static ?string $navigationGroup = 'Rank Predictor';

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static ?string $navigationLabel = 'Admission Processes';

    protected static ?int $navigationSort = 15;

    public static function form(Form $form): Form
    {
        return $form->schema([
            TextInput::make('name')->required()->placeholder('Counselling')->unique(ignoreRecord: true),
            TextInput::make('code')->required()->maxLength(32)->placeholder('COUNSELLING')->unique(ignoreRecord: true),
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
        return ['index' => Pages\ListAdmissionProcesses::route('/')];
    }
}
