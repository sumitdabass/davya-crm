<?php

namespace App\Filament\Support;

use App\Filament\Resources\Shared\PaymentFormSchema;
use App\Models\Payment;
use App\Models\Payout;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Group;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Notifications\Notification;
use Illuminate\Support\Arr;

class PaymentPayoutChooser
{
    public static function dealAction(): Action
    {
        return Action::make('editDeal')
            ->label('Edit deal amount')
            ->modalHeading('Edit deal amount')
            ->modalWidth('sm')
            ->modalSubmitActionLabel('Save')
            ->fillForm(fn ($livewire) => ['deal_amount' => $livewire->getRecord()->deal_amount])
            ->form([
                TextInput::make('deal_amount')->label('Deal amount')->numeric()->prefix('₹'),
            ])
            ->action(function (array $data, $livewire) {
                $livewire->getRecord()->update(['deal_amount' => $data['deal_amount']]);
                Notification::make()->success()->title('Deal amount updated')->send();
            });
    }

    public static function paymentAction(): Action
    {
        return Action::make('managePayment')
            ->label('Payment')
            ->modalHeading('Payment')
            ->modalWidth('xl')
            ->modalSubmitActionLabel('Save')
            ->form([
                ToggleButtons::make('entry_action')
                    ->label('What do you want to do?')
                    ->options(['add' => 'Add', 'update' => 'Update', 'delete' => 'Delete'])
                    ->icons(['add' => 'heroicon-o-plus', 'update' => 'heroicon-o-pencil-square', 'delete' => 'heroicon-o-trash'])
                    ->inline()->live()->required()->default('add')->columnSpanFull(),

                Select::make('payment_id')
                    ->label('Which payment?')
                    ->options(fn ($livewire) => $livewire->getRecord()->payments()
                        ->latest('received_at')->get()
                        ->mapWithKeys(fn ($p) => [$p->id => '₹'.number_format((float) $p->amount, 0).' · '.$p->type.' · '.$p->received_at?->format('d M Y')])
                        ->all())
                    ->live()->required()
                    ->visible(fn (Get $get) => in_array($get('entry_action'), ['update', 'delete'], true))
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

                Placeholder::make('delete_warning')->label('')
                    ->content('This permanently deletes the selected payment.')
                    ->visible(fn (Get $get) => $get('entry_action') === 'delete')
                    ->columnSpanFull(),

                Group::make(PaymentFormSchema::fields(inlineFirstPayment: false))
                    ->visible(fn (Get $get) => in_array($get('entry_action'), ['add', 'update'], true))
                    ->columns(['default' => 1, 'md' => 2])
                    ->columnSpanFull(),
            ])
            ->action(function (array $data, $livewire) {
                $student = $livewire->getRecord();
                $attrs = function (array $d): array {
                    $d = PaymentFormSchema::resolveProofUpload($d);

                    return Arr::only($d, ['type', 'amount', 'mode', 'reference_number', 'received_at', 'proof_url', 'notes']);
                };

                switch ($data['entry_action']) {
                    case 'add':
                        $student->payments()->create($attrs($data) + ['recorded_by_user_id' => auth()->id()]);
                        $title = 'Payment recorded';
                        break;
                    case 'update':
                        Payment::findOrFail($data['payment_id'])->update($attrs($data));
                        $title = 'Payment updated';
                        break;
                    case 'delete':
                        Payment::findOrFail($data['payment_id'])->delete();
                        $title = 'Payment deleted';
                        break;
                    default:
                        $title = 'Saved';
                }

                Notification::make()->success()->title($title)->send();
            });
    }

    public static function payoutAction(): Action
    {
        return Action::make('managePayout')
            ->label('Payout')
            ->modalHeading('Payout')
            ->modalWidth('xl')
            ->modalSubmitActionLabel('Save')
            ->form([
                ToggleButtons::make('entry_action')
                    ->label('What do you want to do?')
                    ->options(['add' => 'Add', 'update' => 'Update', 'delete' => 'Delete'])
                    ->icons(['add' => 'heroicon-o-plus', 'update' => 'heroicon-o-pencil-square', 'delete' => 'heroicon-o-trash'])
                    ->inline()->live()->required()->default('add')->columnSpanFull(),

                Select::make('payout_id')
                    ->label('Which payout?')
                    ->options(fn ($livewire) => $livewire->getRecord()->payouts()
                        ->latest()->get()
                        ->mapWithKeys(fn ($po) => [$po->id => '₹'.number_format((float) $po->amount, 0).' · '.ucfirst($po->payee_type).' · '.$po->created_at?->format('d M Y')])
                        ->all())
                    ->live()->required()
                    ->visible(fn (Get $get) => in_array($get('entry_action'), ['update', 'delete'], true))
                    ->afterStateUpdated(function ($state, Set $set) {
                        $po = Payout::find($state);
                        if (! $po) {
                            return;
                        }
                        $set('payee_type', $po->payee_type);
                        $set('payee_name', $po->payee_name);
                        $set('amount', $po->amount);
                        $set('status', $po->status);
                        $set('paid_at', $po->paid_at);
                        $set('notes', $po->notes);
                    }),

                Placeholder::make('delete_warning')->label('')
                    ->content('This permanently deletes the selected payout.')
                    ->visible(fn (Get $get) => $get('entry_action') === 'delete')
                    ->columnSpanFull(),

                Group::make([
                    Select::make('payee_type')->label('Payee')
                        ->options(['college' => 'College', 'other' => 'Other'])->default('college')->required(),
                    TextInput::make('payee_name')->label('Payee name')->placeholder('College / party name')->maxLength(120),
                    TextInput::make('amount')->numeric()->prefix('₹')->required(),
                    Select::make('status')->options(['to_pay' => 'To be paid', 'paid' => 'Paid'])->default('to_pay')->live()->required(),
                    DateTimePicker::make('paid_at')->label('Paid on')
                        ->visible(fn (Get $get) => $get('status') === 'paid'),
                    Textarea::make('notes')->rows(2)->columnSpanFull(),
                ])
                    ->visible(fn (Get $get) => in_array($get('entry_action'), ['add', 'update'], true))
                    ->columns(['default' => 1, 'md' => 2])
                    ->columnSpanFull(),
            ])
            ->action(function (array $data, $livewire) {
                $student = $livewire->getRecord();
                $attrs = fn (array $d): array => Arr::only($d, ['payee_type', 'payee_name', 'amount', 'status', 'paid_at', 'notes']);

                switch ($data['entry_action']) {
                    case 'add':
                        $student->payouts()->create($attrs($data) + ['recorded_by_user_id' => auth()->id()]);
                        $title = 'Payout recorded';
                        break;
                    case 'update':
                        Payout::findOrFail($data['payout_id'])->update($attrs($data));
                        $title = 'Payout updated';
                        break;
                    case 'delete':
                        Payout::findOrFail($data['payout_id'])->delete();
                        $title = 'Payout deleted';
                        break;
                    default:
                        $title = 'Saved';
                }

                Notification::make()->success()->title($title)->send();
            });
    }
}
