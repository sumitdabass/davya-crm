<?php

namespace App\Filament\Resources\StudentResource\RelationManagers;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class PaymentsRelationManager extends RelationManager
{
    protected static string $relationship = 'payments';

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Select::make('type')
                ->options([
                    'advance' => 'Advance',
                    'partial' => 'Partial',
                    'full' => 'Full',
                    'refund' => 'Refund',
                ])
                ->required(),
            Forms\Components\TextInput::make('amount')
                ->numeric()
                ->prefix('₹')
                ->required(),
            Forms\Components\Select::make('mode')
                ->options([
                    'cash' => 'Cash',
                    'upi' => 'UPI',
                    'bank_transfer' => 'Bank Transfer',
                    'card' => 'Card',
                    'cheque' => 'Cheque',
                    'other' => 'Other',
                ]),
            Forms\Components\TextInput::make('reference_number')
                ->maxLength(80),
            Forms\Components\DateTimePicker::make('received_at')
                ->required()
                ->default(now()),
            Forms\Components\FileUpload::make('proof_drive_url')
                ->disk('drive')
                ->directory('Payment Proofs'),
            Forms\Components\Textarea::make('notes')
                ->rows(2),
            Forms\Components\Hidden::make('recorded_by_user_id')
                ->default(fn () => auth()->id()),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('type')
            ->columns([
                Tables\Columns\TextColumn::make('received_at')->dateTime('d M Y H:i')->sortable(),
                Tables\Columns\TextColumn::make('type')->badge(),
                Tables\Columns\TextColumn::make('amount')->money('INR'),
                Tables\Columns\TextColumn::make('mode'),
                Tables\Columns\TextColumn::make('recordedBy.name')->label('Recorded by'),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make(),
            ])
            ->actions([
                Tables\Actions\Action::make('open_proof')
                    ->label('Open proof')
                    ->icon('heroicon-o-document-arrow-down')
                    ->url(fn ($record) => $record->proof_drive_url)
                    ->openUrlInNewTab()
                    ->visible(fn ($record) => filled($record->proof_drive_url)),
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
