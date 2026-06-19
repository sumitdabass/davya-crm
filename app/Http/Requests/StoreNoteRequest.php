<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class StoreNoteRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'body'             => ['required', 'string', 'max:4000'],
            'slack_message_id' => ['required', 'string', 'max:50'],
            'raw_input'        => ['nullable', 'string', 'max:4000'],
            'noted_at'         => ['nullable', 'date'],
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
