<?php

namespace App\Filament\Resources\StudentResource\RelationManagers;

use App\Filament\Resources\Shared\PaymentFormSchema;
use App\Models\Payment;
use App\Models\Payout;
use App\Support\MoneyFormat;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Group;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Arr;

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
            ->headerActions([
                Tables\Actions\Action::make('newPaymentPayout')
                    ->label('New payment / payout')
                    ->icon('heroicon-o-banknotes')
                    ->color('success')
                    ->modalHeading('New payment / payout')
                    ->modalWidth('xl')
                    ->modalSubmitActionLabel('Save')
                    ->form([
                        ToggleButtons::make('entry_action')
                            ->label('What do you want to do?')
                            ->options([
                                'add_payment' => 'Add Payment',
                                'update_payment' => 'Update Payment',
                                'add_payout' => 'Add Payout',
                                'update_payout' => 'Update Payout',
                            ])
                            ->icons([
                                'add_payment' => 'heroicon-o-plus',
                                'update_payment' => 'heroicon-o-pencil-square',
                                'add_payout' => 'heroicon-o-plus',
                                'update_payout' => 'heroicon-o-pencil-square',
                            ])
                            ->inline()
                            ->live()
                            ->required()
                            ->default('add_payment')
                            ->columnSpanFull(),

                        Select::make('payment_id')
                            ->label('Which payment?')
                            ->options(fn ($livewire) => $livewire->getOwnerRecord()->payments()
                                ->latest('received_at')->get()
                                ->mapWithKeys(fn ($p) => [$p->id => '₹'.number_format((float) $p->amount, 0).' · '.$p->type.' · '.$p->received_at?->format('d M Y')])
                                ->all())
                            ->live()
                            ->required()
                            ->visible(fn (Get $get) => $get('entry_action') === 'update_payment')
                            ->afterStateUpdated(function ($state, Set $set) {
                                $p = Payment::find($state);
                                if (! $p) {
                                    return;
                                }
                                $set('type', $p->type);
                                $set('amount', $p->amount);
                                $set('mode', $p->mode);
                                $set('reference_number', $p->reference_number);
                                $set('received_at', $p->received_at);
                                $set('proof_url', $p->proof_url);
                                $set('notes', $p->notes);
                            }),

                        Select::make('payout_id')
                            ->label('Which payout?')
                            ->options(fn ($livewire) => $livewire->getOwnerRecord()->payouts()
                                ->latest()->get()
                                ->mapWithKeys(fn ($po) => [$po->id => '₹'.number_format((float) $po->amount, 0).' · '.ucfirst($po->payee_type).' · '.$po->created_at?->format('d M Y')])
                                ->all())
                            ->live()
                            ->required()
                            ->visible(fn (Get $get) => $get('entry_action') === 'update_payout')
                            ->afterStateUpdated(function ($state, Set $set) {
                                $po = Payout::find($state);
                                if (! $po) {
                                    return;
                                }
                                $set('payout_payee_type', $po->payee_type);
                                $set('payout_payee_name', $po->payee_name);
                                $set('payout_amount', $po->amount);
                                $set('payout_status', $po->status);
                                $set('payout_paid_at', $po->paid_at);
                                $set('payout_notes', $po->notes);
                            }),

                        Group::make(PaymentFormSchema::fields(inlineFirstPayment: false))
                            ->visible(fn (Get $get) => in_array($get('entry_action'), ['add_payment', 'update_payment'], true))
                            ->columns(['default' => 1, 'md' => 2])
                            ->columnSpanFull(),

                        Group::make([
                            Select::make('payout_payee_type')->label('Payee')
                                ->options(['college' => 'College', 'other' => 'Other'])
                                ->default('college')->required(),
                            TextInput::make('payout_payee_name')->label('Payee name')
                                ->placeholder('College / party name')->maxLength(120),
                            TextInput::make('payout_amount')->label('Amount')->numeric()->prefix('₹')->required(),
                            Select::make('payout_status')->label('Status')
                                ->options(['to_pay' => 'To be paid', 'paid' => 'Paid'])
                                ->default('to_pay')->live()->required(),
                            DateTimePicker::make('payout_paid_at')->label('Paid on')
                                ->visible(fn (Get $get) => $get('payout_status') === 'paid'),
                            Textarea::make('payout_notes')->label('Notes')->rows(2)->columnSpanFull(),
                        ])
                            ->visible(fn (Get $get) => in_array($get('entry_action'), ['add_payout', 'update_payout'], true))
                            ->columns(['default' => 1, 'md' => 2])
                            ->columnSpanFull(),
                    ])
                    ->action(function (array $data, $livewire) {
                        $student = $livewire->getOwnerRecord();

                        $paymentAttrs = function (array $d): array {
                            $d = PaymentFormSchema::resolveProofUpload($d);

                            return Arr::only($d, ['type', 'amount', 'mode', 'reference_number', 'received_at', 'proof_url', 'notes']);
                        };
                        $payoutAttrs = fn (array $d): array => [
                            'payee_type' => $d['payout_payee_type'] ?? 'college',
                            'payee_name' => $d['payout_payee_name'] ?? null,
                            'amount' => $d['payout_amount'] ?? 0,
                            'status' => $d['payout_status'] ?? 'to_pay',
                            'paid_at' => $d['payout_paid_at'] ?? null,
                            'notes' => $d['payout_notes'] ?? null,
                        ];

                        switch ($data['entry_action']) {
                            case 'add_payment':
                                $student->payments()->create($paymentAttrs($data) + ['recorded_by_user_id' => auth()->id()]);
                                $title = 'Payment recorded';
                                break;
                            case 'update_payment':
                                Payment::findOrFail($data['payment_id'])->update($paymentAttrs($data));
                                $title = 'Payment updated';
                                break;
                            case 'add_payout':
                                $student->payouts()->create($payoutAttrs($data) + ['recorded_by_user_id' => auth()->id()]);
                                $title = 'Payout recorded';
                                break;
                            case 'update_payout':
                                Payout::findOrFail($data['payout_id'])->update($payoutAttrs($data));
                                $title = 'Payout updated';
                                break;
                            default:
                                $title = 'Saved';
                        }

                        Notification::make()->success()->title($title)->send();
                    }),
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
