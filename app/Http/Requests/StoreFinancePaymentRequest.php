<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class StoreFinancePaymentRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'student_phone' => $this->digitsOnly($this->input('student_phone')),
        ]);
    }

    public function rules(): array
    {
        return [
            'student_phone'    => ['required', 'string', 'regex:/^\d{10}$/'],
            'amount'           => ['required', 'numeric', 'gt:0', 'lte:10000000'],
            'student_name'     => ['nullable', 'string', 'max:120'],
            'referrer_name'    => ['nullable', 'string', 'max:60'],
            'is_partial'       => ['nullable', 'boolean'],
            'received_at'      => ['nullable', 'date'],
            'slack_message_id' => ['required', 'string', 'max:50'],
            'raw_input'        => ['nullable', 'string', 'max:4000'],
            'proof_url'        => ['nullable', 'url', 'max:2048'],
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json([
            'message' => 'The given data was invalid.',
            'errors'  => $validator->errors(),
        ], 422));
    }

    private function digitsOnly(?string $v): ?string
    {
        if ($v === null || $v === '') return null;
        $d = preg_replace('/\D+/', '', $v);
        if (strlen($d) === 12 && str_starts_with($d, '91')) $d = substr($d, 2);
        return $d;
    }
}
