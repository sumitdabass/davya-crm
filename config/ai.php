<?php
return [
    'provider' => env('AI_PROVIDER', 'groq'),
    'providers' => [
        'groq' => [
            'key'   => env('AI_GROQ_KEY'),
            'model' => env('AI_GROQ_MODEL', 'llama-3.3-70b-versatile'),
            'base_url' => env('AI_GROQ_BASE_URL', 'https://api.groq.com/openai/v1'),
            'timeout_seconds' => (int) env('AI_GROQ_TIMEOUT', 30),
        ],
    ],
    'daily_cap_per_user'   => (int) env('AI_DAILY_CAP', 50),
    'max_history_turns'    => (int) env('AI_MAX_HISTORY_TURNS', 10),
    'max_tool_roundtrips'  => (int) env('AI_MAX_TOOL_ROUNDTRIPS', 3),
    'ipu_docroot'          => env('AI_IPU_DOCROOT', '/home/ipuc/public_html'),
    'excluded_dirs'        => ['api', 'assets', 'cgi-bin', 'include'],
    'read_page_byte_cap'   => 16384,
];
