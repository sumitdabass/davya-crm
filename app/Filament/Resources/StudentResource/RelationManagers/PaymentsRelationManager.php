<?php

namespace App\Filament\Resources\StudentResource\RelationManagers;

use App\Filament\Resources\Shared\PaymentFormSchema;
use App\Support\MoneyFormat;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class PaymentsRelationManager extends RelationManager
{
    protected static string $relationship = 'payments';

    public function form(Form $form): Form
    {
        return $form->schema(PaymentFormSchema::fields(inlineFirstPayment: false));
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('type')
            ->columns([
                Tables\Columns\TextColumn::make('received_at')->since()
                    ->tooltip(fn ($record) => $record->received_at?->format('d M Y, H:i'))->sortable(),
                Tables\Columns\TextColumn::make('type')->badge(),
                Tables\Columns\TextColumn::make('amount')
                    ->formatStateUsing(fn ($state) => MoneyFormat::asInlineHtml((float) $state))
                    ->html(),
                Tables\Columns\TextColumn::make('mode'),
                Tables\Columns\TextColumn::make('recordedBy.name')->label('Recorded by'),
            ])
            ->actions([
                Tables\Actions\Action::make('open_proof')
                    ->label('Open proof')
                    ->icon('heroicon-o-document-arrow-down')
                    ->url(fn ($record) => $record->proof_url)
                    ->openUrlInNewTab()
                    ->visible(fn ($record) => filled($record->proof_url)),
                Tables\Actions\EditAction::make()
                    ->mutateFormDataUsing(fn (array $data): array => PaymentFormSchema::resolveProofUpload($data)),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }
}
