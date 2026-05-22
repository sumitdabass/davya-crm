<?php
namespace App\Services\Ai\Providers;

use App\Services\Ai\LlmProvider;
use App\Services\Ai\LlmResponse;
use Illuminate\Support\Facades\Http;

class GroqProvider implements LlmProvider
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $model,
        private readonly string $baseUrl,
        private readonly int $timeoutSeconds,
    ) {}

    public function chat(array $messages, array $tools = []): LlmResponse
    {
        $payload = [
            'model'       => $this->model,
            'messages'    => $messages,
            'temperature' => 0.2,
        ];
        if ($tools !== []) {
            $payload['tools'] = $tools;
            $payload['tool_choice'] = 'auto';
            $payload['parallel_tool_calls'] = false;
        }

        $start = microtime(true);
        $response = Http::withToken($this->apiKey)
            ->timeout($this->timeoutSeconds)
            ->acceptJson()
            ->asJson()
            ->post(rtrim($this->baseUrl, '/').'/chat/completions', $payload);
        $latency = (int) ((microtime(true) - $start) * 1000);

        if (!$response->successful()) {
            throw new GroqException(
                "Groq HTTP {$response->status()}: ".substr($response->body(), 0, 500),
                $response->status(),
            );
        }

        $body = $response->json();
        $msg = $body['choices'][0]['message'] ?? [];
        $content = (string) ($msg['content'] ?? '');

        $toolCalls = null;
        if (!empty($msg['tool_calls'])) {
            $toolCalls = array_map(fn (array $tc) => [
                'id'        => $tc['id'] ?? '',
                'name'      => $tc['function']['name'] ?? '',
                'arguments' => json_decode($tc['function']['arguments'] ?? '{}', true) ?: [],
            ], $msg['tool_calls']);
        }

        return new LlmResponse(
            content:     $content,
            toolCalls:   $toolCalls,
            tokenInput:  (int) ($body['usage']['prompt_tokens'] ?? 0),
            tokenOutput: (int) ($body['usage']['completion_tokens'] ?? 0),
            latencyMs:   $latency,
            model:       (string) ($body['model'] ?? $this->model),
        );
    }
}
