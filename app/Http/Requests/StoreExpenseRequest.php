<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class StoreExpenseRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'amount'           => ['required', 'numeric', 'gt:0', 'lte:10000000'],
            'category'         => ['nullable', 'string', 'max:60'],
            'description'      => ['nullable', 'string', 'max:4000'],
            'paid_at'          => ['nullable', 'date'],
            'slack_message_id' => ['required', 'string', 'max:50'],
            'raw_input'        => ['nullable', 'string', 'max:4000'],
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json([
            'message' => 'The given data was invalid.',
            'errors'  => $validator->errors(),
        ], 422));
    }
}
