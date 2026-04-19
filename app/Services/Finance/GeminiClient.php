<?php

declare(strict_types=1);

namespace App\Services\Finance;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class GeminiClient
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $model = 'gemini-2.5-flash',
        private readonly int $timeoutSeconds = 30,
    ) {}

    public function generate(string $systemPrompt, array $userJson): string
    {
        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$this->model}:generateContent?key={$this->apiKey}";

        $body = [
            'systemInstruction' => ['parts' => [['text' => $systemPrompt]]],
            'contents' => [[
                'role'  => 'user',
                'parts' => [['text' => json_encode($userJson, JSON_UNESCAPED_UNICODE)]],
            ]],
            'generationConfig' => ['temperature' => 0.2],
        ];

        $response = Http::timeout($this->timeoutSeconds)
            ->acceptJson()
            ->asJson()
            ->post($url, $body);

        if (!$response->successful()) {
            throw new RuntimeException('Gemini non-200: '.$response->status().' — '.$response->body());
        }

        $text = $response->json('candidates.0.content.parts.0.text');
        if (!is_string($text) || $text === '') {
            throw new RuntimeException('Gemini returned empty text');
        }

        return trim($text);
    }
}
