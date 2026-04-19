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

        // STUB — replaced in M3/M4 with resolver + answerer
        $replyText = "🔧 Assistant online — stub reply for intent `{$data['intent']}`. Implementation arrives in M3.";

        return response()->json(['reply_text' => $replyText]);
    }
}
