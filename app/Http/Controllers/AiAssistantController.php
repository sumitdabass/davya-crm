<?php
namespace App\Http\Controllers;

use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Services\Ai\AssistantService;
use App\Services\Ai\Providers\GroqException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class AiAssistantController extends Controller
{
    public function ask(Request $request, AssistantService $svc): JsonResponse
    {
        $user = $request->user();
        abort_unless($user && $user->can('use ai-agent'), 403);

        $data = $request->validate([
            'question' => ['required', 'string', 'min:1', 'max:2000'],
            'conversation_id' => ['nullable', 'integer', 'exists:ai_conversations,id'],
        ]);

        $cap = (int) config('ai.daily_cap_per_user', 50);
        $startOfDay = now()->startOfDay();
        $todayCount = AiMessage::where('role', 'user')
            ->whereHas('conversation', fn($q) => $q->where('user_id', $user->id))
            ->where('created_at', '>=', $startOfDay)
            ->count();
        if ($todayCount >= $cap) {
            return response()->json([
                'error' => "Hit today's question cap ($cap). Resets midnight IST.",
            ], 429);
        }

        $conversation = isset($data['conversation_id'])
            ? AiConversation::where('user_id', $user->id)->findOrFail($data['conversation_id'])
            : AiConversation::create([
                'user_id'         => $user->id,
                'title'           => mb_substr($data['question'], 0, 60),
                'started_at'      => now(),
                'last_message_at' => now(),
            ]);

        try {
            // AssistantService persists user + trace + final; rolls back user on Groq failure.
            $assistantMsg = $svc->ask($conversation, $data['question']);
        } catch (GroqException $e) {
            Log::warning('Groq failed', ['status' => $e->status, 'msg' => $e->getMessage()]);
            return response()->json([
                'error' => $e->status === 429
                    ? 'Busy, try in a moment.'
                    : 'Something went wrong. Try again.',
            ], 503);
        }

        return response()->json([
            'conversation_id' => $conversation->id,
            'answer'          => $assistantMsg->content,
            'citations'       => $assistantMsg->citations,
        ]);
    }
}
