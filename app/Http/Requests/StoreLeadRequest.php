<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class StoreLeadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'phone'   => $this->digitsOnly($this->input('phone')),
            'phone_2' => $this->digitsOnly($this->input('phone_2')),
        ]);
    }

    public function rules(): array
    {
        return [
            'phone'          => ['required', 'string', 'regex:/^\d{10}$/'],
            'course'         => ['required', 'string', 'max:80'],
            'name'           => ['nullable', 'string', 'max:120'],
            'father_name'    => ['nullable', 'string', 'max:120'],
            'phone_2'        => ['nullable', 'string', 'regex:/^\d{10}$/'],
            'email'          => ['nullable', 'string', 'max:120'],
            'exam_appeared'  => ['nullable', 'string', 'in:IPU CET,CUET,JEE,Other'],
            'twelfth_marks'  => ['nullable', 'string', 'max:20'],
            'rank'           => ['nullable', 'string', 'max:40'],
            'category'       => ['nullable', 'string', 'in:Delhi,Outside'],
            'state'          => ['nullable', 'string', 'max:40'],
            'college'        => ['nullable', 'string', 'max:120'],
            'referrer_name'  => ['nullable', 'string', 'max:60'],
            'owner_name'     => ['nullable', 'string', 'max:60'],
            'remarks'        => ['nullable', 'string', 'max:2000'],
            'source'         => ['nullable', 'string', 'max:60'],
            'description'    => ['nullable', 'string', 'max:2000'],
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
        if ($v === null || $v === '') {
            return null;
        }
        $digits = preg_replace('/\D+/', '', $v);
        if (strlen($digits) === 12 && str_starts_with($digits, '91')) {
            $digits = substr($digits, 2);
        }
        return $digits;
    }
}
