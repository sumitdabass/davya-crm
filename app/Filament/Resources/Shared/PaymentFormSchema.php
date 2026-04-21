<?php

namespace App\Filament\Resources\Shared;

use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Get;
use Illuminate\Support\Facades\Storage;

final class PaymentFormSchema
{
    public const DRIVE_DISK = 'drive';
    public const UPLOAD_DIRECTORY = 'payment-proofs';

    /**
     * @return array<\Filament\Forms\Components\Component>
     */
    public static function fields(bool $inlineFirstPayment = false): array
    {
        $typeField = Select::make('type')
            ->options([
                'advance' => 'Advance',
                'partial' => 'Partial',
                'full'    => 'Full',
                'refund'  => 'Refund',
            ]);

        $amountField = TextInput::make('amount')
            ->numeric()
            ->prefix('₹');

        $receivedAtField = DateTimePicker::make('received_at')->default(now());

        if ($inlineFirstPayment) {
            $typeField        = $typeField->required(fn (Get $get) => filled($get('amount')));
            $receivedAtField  = $receivedAtField->required(fn (Get $get) => filled($get('amount')));
        } else {
            $typeField        = $typeField->required();
            $amountField      = $amountField->required();
            $receivedAtField  = $receivedAtField->required();
        }

        return [
            $typeField,
            $amountField,
            Select::make('mode')->options([
                'cash'          => 'Cash',
                'upi'           => 'UPI',
                'bank_transfer' => 'Bank Transfer',
                'card'          => 'Card',
                'cheque'        => 'Cheque',
                'other'         => 'Other',
            ]),
            TextInput::make('reference_number')->maxLength(80),
            $receivedAtField,
            FileUpload::make('proof_upload')
                ->label('Upload proof')
                ->disk(self::DRIVE_DISK)
                ->directory(self::UPLOAD_DIRECTORY)
                ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp', 'application/pdf'])
                ->maxSize(5120)
                ->visibility('private')
                ->helperText('Optional — uploads to Google Drive. Leave empty to paste a URL instead.'),
            TextInput::make('proof_url')
                ->label('Proof URL')
                ->placeholder('https://...')
                ->url()
                ->maxLength(2048),
            Textarea::make('notes')->rows(2),
            Hidden::make('recorded_by_user_id')->default(fn () => auth()->id()),
        ];
    }

    /**
     * Resolve a pending upload path to a Drive URL and remove the transient
     * proof_upload key. Always strips proof_upload from the returned array.
     */
    public static function resolveProofUpload(array $data): array
    {
        $uploadPath = $data['proof_upload'] ?? null;
        unset($data['proof_upload']);

        if (is_string($uploadPath) && $uploadPath !== '') {
            $data['proof_url'] = Storage::disk(self::DRIVE_DISK)->url($uploadPath);
        } elseif (! array_key_exists('proof_url', $data)) {
            $data['proof_url'] = null;
        }

        return $data;
    }
}
