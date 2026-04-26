<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\ClaudeCodeService;
use App\Services\TelegramService;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(TelegramService::class, function ($app): TelegramService {
            $cfg = $app['config']->get('ai.telegram');

            return new TelegramService(
                botToken: (string) ($cfg['bot_token'] ?? ''),
                apiUrl: (string) ($cfg['api_url'] ?? 'https://api.telegram.org'),
                timeout: (int) ($cfg['timeout'] ?? 15),
            );
        });

        $this->app->singleton(ClaudeCodeService::class, function ($app): ClaudeCodeService {
            $cfg = $app['config']->get('ai.claude');

            return new ClaudeCodeService(
                binaryPath: (string) $cfg['binary_path'],
                apiKey: $cfg['api_key'] ?? null,
                defaultModel: (string) $cfg['default_model'],
                timeout: (int) $cfg['timeout'],
                maxTurns: (int) $cfg['max_turns'],
                retryAttempts: (int) ($cfg['retry']['attempts'] ?? 3),
                retryBackoffMs: (array) ($cfg['retry']['backoff_ms'] ?? [1000, 3000, 8000]),
            );
        });

    }

    public function boot(): void
    {
        //
    }
}
