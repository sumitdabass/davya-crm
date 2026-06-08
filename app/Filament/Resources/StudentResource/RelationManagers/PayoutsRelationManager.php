<?php

namespace App\Filament\Resources\StudentResource\RelationManagers;

use App\Support\MoneyFormat;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class PayoutsRelationManager extends RelationManager
{
    protected static string $relationship = 'payouts';

    public function form(Form $form): Form
    {
        return $form->schema([
            Select::make('payee_type')->label('Payee')
                ->options(['college' => 'College', 'other' => 'Other'])
                ->default('college')->required(),
            TextInput::make('payee_name')->label('Payee name')->maxLength(120),
            TextInput::make('amount')->numeric()->prefix('₹')->required(),
            Select::make('status')
                ->options(['to_pay' => 'To be paid', 'paid' => 'Paid'])
                ->default('to_pay')->live()->required(),
            DateTimePicker::make('paid_at')->label('Paid on')
                ->visible(fn (Get $get) => $get('status') === 'paid'),
            Textarea::make('notes')->rows(2)->columnSpanFull(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('payee_type')
            ->columns([
                Tables\Columns\TextColumn::make('created_at')->since()
                    ->tooltip(fn ($record) => $record->created_at?->format('d M Y, H:i'))->sortable(),
                Tables\Columns\TextColumn::make('payee_type')->label('Payee')->badge(),
                Tables\Columns\TextColumn::make('payee_name')->label('Name'),
                Tables\Columns\TextColumn::make('amount')
                    ->formatStateUsing(fn ($state) => MoneyFormat::asInlineHtml((float) $state))->html(),
                Tables\Columns\TextColumn::make('status')->badge()
                    ->color(fn ($state) => $state === 'paid' ? 'success' : 'warning'),
                Tables\Columns\TextColumn::make('recordedBy.name')->label('Recorded by'),
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
