<?php

namespace App\Filament\Resources\StudentResource\RelationManagers;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class RoundHistoryRelationManager extends RelationManager
{
    protected static string $relationship = 'roundHistory';

    private const ROUND_NAMES = [
        'Online_R1' => 'Online R1',
        'Online_R2' => 'Online R2',
        'Online_R3' => 'Online R3',
        'Online_Sliding' => 'Online Sliding',
        'Online_Reporting' => 'Online Reporting',
        'S2_R1' => 'S2 R1',
        'S2_R3' => 'S2 R3',
        'Offline_R1' => 'Offline R1',
        'Offline_R2' => 'Offline R2',
    ];

    private const OUTCOMES = [
        'Not Allotted' => 'Not Allotted',
        'Allotted — Fee Pending' => 'Allotted — Fee Pending',
        'Allotted — Fee Paid' => 'Allotted — Fee Paid',
        'Kicked Out — Fee Unpaid' => 'Kicked Out — Fee Unpaid',
        'Allotted — Frozen (Final)' => 'Allotted — Frozen (Final)',
    ];

    private const OUTCOME_COLORS = [
        'Not Allotted' => 'gray',
        'Allotted — Fee Pending' => 'warning',
        'Allotted — Fee Paid' => 'success',
        'Kicked Out — Fee Unpaid' => 'danger',
        'Allotted — Frozen (Final)' => 'info',
    ];

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Select::make('round_name')
                ->options(self::ROUND_NAMES)
                ->required(),
            Forms\Components\TextInput::make('allotted_college')
                ->maxLength(120),
            Forms\Components\TextInput::make('allotted_course')
                ->maxLength(120),
            Forms\Components\TextInput::make('seat_fee_amount')
                ->numeric()
                ->prefix('₹'),
            Forms\Components\Toggle::make('seat_fee_paid')
                ->reactive(),
            Forms\Components\DateTimePicker::make('fee_paid_at')
                ->visible(fn (Get $get) => (bool) $get('seat_fee_paid')),
            Forms\Components\Select::make('outcome')
                ->options(self::OUTCOMES)
                ->required(),
            Forms\Components\Textarea::make('notes')
                ->rows(2),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('round_name')
            ->columns([
                Tables\Columns\TextColumn::make('created_at')->since()
                    ->tooltip(fn ($record) => $record->created_at?->format('d M Y, H:i'))->sortable(),
                Tables\Columns\TextColumn::make('round_name')->badge(),
                Tables\Columns\TextColumn::make('allotted_college'),
                Tables\Columns\TextColumn::make('outcome')
                    ->badge()
                    ->color(fn (string $state): string => self::OUTCOME_COLORS[$state] ?? 'gray'),
                Tables\Columns\IconColumn::make('seat_fee_paid')->boolean(),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make(),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }
}
