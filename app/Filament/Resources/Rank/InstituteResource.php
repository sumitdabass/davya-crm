<?php

namespace App\Filament\Resources\Rank;

use App\Filament\Resources\Rank\Concerns\RestrictsToRankRoles;
use App\Filament\Resources\Rank\InstituteResource\Pages;
use App\Models\Rank\Institute;
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

class InstituteResource extends Resource
{
    use RestrictsToRankRoles;

    protected static ?string $model = Institute::class;

    protected static ?string $navigationGroup = 'Rank Predictor';

    protected static ?string $navigationIcon = 'heroicon-o-building-library';

    protected static ?int $navigationSort = 12;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Select::make('university_id')
                ->label('University')
                ->options(University::pluck('name', 'id'))
                ->required()
                ->searchable(),
            TextInput::make('name')->required()->columnSpanFull(),
            TextInput::make('code')->maxLength(64),
            TextInput::make('city'),
            TextInput::make('official_website')->url()->prefix('https://')->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('university.code')->label('Univ')->badge(),
                TextColumn::make('name')->searchable()->sortable()->limit(50)->wrap(),
                TextColumn::make('city')->sortable()->toggleable(),
                TextColumn::make('official_website')->url(fn ($r) => $r->official_website, true)->limit(30)->toggleable(),
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
        return ['index' => Pages\ListInstitutes::route('/')];
    }
}
