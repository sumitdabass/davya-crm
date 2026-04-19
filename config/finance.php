<?php

return [
    'capture_token' => env('FINANCE_CAPTURE_TOKEN'),

    'assistant' => [
        'gemini_api_key'         => env('GEMINI_API_KEY'),
        'model'                  => env('GEMINI_MODEL', 'gemini-2.5-flash'),
        'row_cap'                => (int) env('FINANCE_ASSISTANT_ROW_CAP', 200),
        'gemini_timeout_seconds' => (int) env('FINANCE_ASSISTANT_GEMINI_TIMEOUT', 30),
    ],
];
