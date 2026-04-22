<?php

namespace App\Filament\Resources\StudentResource\Pages;

use App\Filament\Resources\Shared\PaymentFormSchema;
use App\Filament\Resources\StudentResource;
use App\Models\Payment;
use Filament\Resources\Pages\CreateRecord;

class CreateStudent extends CreateRecord
{
    protected static string $resource = StudentResource::class;

    protected ?array $firstPaymentData = null;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $this->firstPaymentData = is_array($data['first_payment'] ?? null) ? $data['first_payment'] : null;
        unset($data['first_payment']);

        return $data;
    }

    protected function afterCreate(): void
    {
        $fp = $this->firstPaymentData;

        if (! is_array($fp) || empty($fp['amount'])) {
            return;
        }

        $fp = PaymentFormSchema::resolveProofUpload($fp);

        Payment::create([
            'student_id'          => $this->record->id,
            'type'                => $fp['type']             ?? null,
            'amount'              => $fp['amount'],
            'mode'                => $fp['mode']             ?? null,
            'reference_number'    => $fp['reference_number'] ?? null,
            'received_at'         => $fp['received_at']      ?? now(),
            'proof_url'           => $fp['proof_url']        ?? null,
            'notes'               => $fp['notes']            ?? null,
            'recorded_by_user_id' => auth()->id(),
        ]);
    }
}
