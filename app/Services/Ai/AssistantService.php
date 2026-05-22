<?php
namespace App\Services\Ai;

use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Services\Ai\Tools\ReadPageTool;
use App\Services\Ai\Tools\SearchPagesTool;

class AssistantService
{
    private const SYSTEM_PROMPT = <<<TXT
You are a counsellor assistant for IPU/GGSIPU admissions at https://ipu.co.in.
Use the search_pages and read_page tools to ground every answer in real ipu.co.in content.
Never fabricate facts. If no relevant page is found, say so plainly.
Keep answers under 200 words. Always end with a "Source:" line citing the slug(s) you read.
TXT;

    public function __construct(
        private readonly LlmProvider $provider,
        private readonly SearchPagesTool $search,
        private readonly ReadPageTool $read,
        private readonly int $maxRoundTrips,
        private readonly int $historyTurns,
    ) {}

    public function ask(AiConversation $conversation, string $userQuestion): AiMessage
    {
        // Persist user turn first so it's part of the loop's history view.
        $userMsg = AiMessage::create([
            'conversation_id' => $conversation->id,
            'role'            => 'user',
            'content'         => $userQuestion,
            'created_at'      => now(),
        ]);

        try {
            return $this->runLoop($conversation);
        } catch (\App\Services\Ai\Providers\GroqException $e) {
            // On provider failure, roll back the user turn so it doesn't tick the daily cap.
            $userMsg->delete();
            throw $e;
        }
    }

    private function runLoop(AiConversation $conversation): AiMessage
    {
        $messages = $this->buildMessages($conversation);
        $tools = [SearchPagesTool::definition(), ReadPageTool::definition()];

        $citations = [];
        $tokenIn = 0; $tokenOut = 0; $latency = 0; $model = '';
        $finalContent = '';
        $lastTextual = '';

        for ($i = 0; $i < $this->maxRoundTrips; $i++) {
            $resp = $this->provider->chat($messages, $tools);
            $tokenIn  += $resp->tokenInput;
            $tokenOut += $resp->tokenOutput;
            $latency  += $resp->latencyMs;
            $model    = $resp->model;

            if (!$resp->wantsTools()) {
                $finalContent = $resp->content;
                break;
            }

            $apiToolCalls = array_map(fn($tc) => [
                'id' => $tc['id'],
                'type' => 'function',
                'function' => [
                    'name' => $tc['name'],
                    'arguments' => json_encode($tc['arguments']),
                ],
            ], $resp->toolCalls);

            // Persist intermediate assistant turn (with tool_calls JSON).
            AiMessage::create([
                'conversation_id' => $conversation->id,
                'role'            => 'assistant',
                'content'         => $resp->content,
                'tool_calls'      => $apiToolCalls,
                'token_input'     => $resp->tokenInput,
                'token_output'    => $resp->tokenOutput,
                'latency_ms'      => $resp->latencyMs,
                'model'           => $resp->model,
                'created_at'      => now(),
            ]);

            // Mirror into the API-format messages array for the next round-trip.
            $messages[] = [
                'role'       => 'assistant',
                'content'    => $resp->content,
                'tool_calls' => $apiToolCalls,
            ];

            foreach ($resp->toolCalls as $tc) {
                $result = $this->executeTool($tc['name'], $tc['arguments'], $citations);
                $resultStr = is_string($result) ? $result : json_encode($result);

                AiMessage::create([
                    'conversation_id' => $conversation->id,
                    'role'            => 'tool',
                    'content'         => $resultStr,
                    'tool_call_id'    => $tc['id'],
                    'created_at'      => now(),
                ]);

                $messages[] = [
                    'role'         => 'tool',
                    'tool_call_id' => $tc['id'],
                    'content'      => $resultStr,
                ];
            }
        }

        if ($finalContent === '') {
            $finalContent = $lastTextual !== ''
                ? $lastTextual
                : "I couldn't pin down an answer in our pages — try rephrasing.";
        }

        $citations = array_values(array_unique($citations));

        if ($citations !== [] && !str_contains($finalContent, 'Source:')) {
            $finalContent .= "\n\nSource: ".implode(', ', $citations);
        } elseif ($citations !== []) {
            $finalContent = preg_replace('/\n*Source:.*$/s', '', $finalContent)
                ."\n\nSource: ".implode(', ', $citations);
        }

        $msg = AiMessage::create([
            'conversation_id' => $conversation->id,
            'role'            => 'assistant',
            'content'         => $finalContent,
            'citations'       => $citations,
            'token_input'     => $tokenIn,
            'token_output'    => $tokenOut,
            'latency_ms'      => $latency,
            'model'           => $model,
            'created_at'      => now(),
        ]);

        $conversation->update(['last_message_at' => now()]);

        return $msg;
    }

    private function buildMessages(AiConversation $conversation): array
    {
        $history = $conversation->messages()
            ->latest('created_at')
            ->limit($this->historyTurns * 2)
            ->get()
            ->reverse()
            ->values();

        $messages = [['role' => 'system', 'content' => self::SYSTEM_PROMPT]];
        foreach ($history as $m) {
            if ($m->role === 'tool') continue; // skip prior tool noise; only carry user/assistant
            $messages[] = ['role' => $m->role, 'content' => $m->content];
        }
        return $messages;
    }

    private function executeTool(string $name, array $args, array &$citations): string
    {
        return match ($name) {
            'search_pages' => json_encode($this->search->execute((string) ($args['query'] ?? ''))),
            'read_page'    => $this->readAndTrack((string) ($args['slug'] ?? ''), $citations),
            default        => 'ERROR: unknown tool',
        };
    }

    private function readAndTrack(string $slug, array &$citations): string
    {
        $out = $this->read->execute($slug);
        if (!str_starts_with($out, 'ERROR:')) {
            $citations[] = $slug;
        }
        return $out;
    }
}
