<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\StoreFinanceAssistantRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class FinanceAssistantController extends Controller
{
    public function handle(StoreFinanceAssistantRequest $request): JsonResponse
    {
        $data = $request->validated();

        Log::info('finance.assistant.received', [
            'slack_message_id' => $data['slack_message_id'],
            'slack_channel'    => $data['slack_channel'],
            'slack_user_id'    => $data['slack_user_id'],
            'intent'           => $data['intent'],
        ]);

        $resolver = new \App\Services\Finance\AssistantQueryResolver(
            rowCap: (int) config('finance.assistant.row_cap', 200),
        );
        $answerer = app(\App\Services\Finance\AssistantAnswerer::class);

        $rows  = $resolver->resolve($data['intent'], $data['time_range'] ?? null, $data['filter'] ?? null);
        $reply = $answerer->answer($data['question_text'], $data['intent'], $rows);

        Log::info('finance.assistant.answered', [
            'intent'    => $data['intent'],
            'reply_len' => strlen($reply),
        ]);

        return response()->json(['reply_text' => $reply]);
    }
}
