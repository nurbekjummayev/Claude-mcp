<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Claude Code CLI
    |--------------------------------------------------------------------------
    */

    'claude' => [
        'binary_path' => env('CLAUDE_BINARY', '/usr/local/bin/claude'),
        'api_key' => env('ANTHROPIC_API_KEY'),
        'default_model' => env('CLAUDE_MODEL', 'claude-sonnet-4-5'),
        'timeout' => (int) env('CLAUDE_TIMEOUT', 300),
        'max_turns' => (int) env('CLAUDE_MAX_TURNS', 10),
        'retry' => [
            'attempts' => 3,
            'backoff_ms' => [1000, 3000, 8000],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | MCP Server
    |--------------------------------------------------------------------------
    */

    'mcp' => [
        'enabled' => filter_var(env('MCP_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
        'transport' => 'http_sse',
        'tools' => [
            'get_articles' => true,
            'search_articles' => true,
            'get_article_content' => true,
            'send_telegram_message' => true,
            'save_conversation' => true,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Pricing (USD per 1M tokens)
    |--------------------------------------------------------------------------
    */

    'pricing' => [
        'claude-sonnet-4-5' => ['input_per_1m' => 3.00, 'output_per_1m' => 15.00],
        'claude-haiku-4-5'  => ['input_per_1m' => 1.00, 'output_per_1m' => 5.00],
        'claude-opus-4-7'   => ['input_per_1m' => 15.00, 'output_per_1m' => 75.00],
    ],

    /*
    |--------------------------------------------------------------------------
    | Telegram
    |--------------------------------------------------------------------------
    */

    'telegram' => [
        'bot_token' => env('TELEGRAM_BOT_TOKEN'),
        'default_chat_id' => env('TELEGRAM_DEFAULT_CHAT_ID'),
        'api_url' => 'https://api.telegram.org',
        'timeout' => 15,
    ],

];
