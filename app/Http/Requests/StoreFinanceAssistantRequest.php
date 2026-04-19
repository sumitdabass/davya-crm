<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreFinanceAssistantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'slack_message_id' => ['required', 'string', 'max:64'],
            'slack_channel'    => ['required', 'string', 'max:32'],
            'slack_user_id'    => ['required', 'string', 'max:32'],
            'question_text'    => ['required', 'string', 'min:1', 'max:2000'],
            'intent'           => ['required', 'string', 'in:payments_by_student,spend_by_category,ledger_balance,recent_captures,totals_by_range,student_status,freeform'],
            'time_range'       => ['nullable', 'array'],
            'time_range.from'  => ['required_with:time_range', 'date'],
            'time_range.to'    => ['required_with:time_range', 'date', 'after_or_equal:time_range.from'],
            'filter'           => ['nullable', 'array'],
        ];
    }
}
