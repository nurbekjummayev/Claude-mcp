<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class TelegramService
{
    public function __construct(
        private readonly string $botToken,
        private readonly string $apiUrl = 'https://api.telegram.org',
        private readonly int $timeout = 15,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function sendMessage(
        string $chatId,
        string $text,
        string $parseMode = 'Markdown',
        bool $disableWebPreview = true,
    ): array {
        $this->ensureToken();

        $payload = [
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => $parseMode,
            'disable_web_page_preview' => $disableWebPreview,
        ];

        $response = Http::timeout($this->timeout)
            ->asJson()
            ->post("{$this->apiUrl}/bot{$this->botToken}/sendMessage", $payload);

        if (! $response->successful()) {
            Log::warning('telegram.sendMessage failed', [
                'chat_id' => $chatId,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            throw new RuntimeException('Telegram API error: '.$response->status());
        }

        return $response->json() ?? [];
    }

    /**
     * @return array<string, mixed>
     */
    public function sendDocument(string $chatId, string $filePath): array
    {
        $this->ensureToken();

        if (! is_file($filePath)) {
            throw new RuntimeException("Document not found: {$filePath}");
        }

        $stream = fopen($filePath, 'rb');
        if ($stream === false) {
            throw new RuntimeException("Cannot read document: {$filePath}");
        }

        $response = Http::timeout($this->timeout)
            ->attach('document', $stream, basename($filePath))
            ->post("{$this->apiUrl}/bot{$this->botToken}/sendDocument", ['chat_id' => $chatId]);

        if (! $response->successful()) {
            throw new RuntimeException('Telegram API error: '.$response->status());
        }

        return $response->json() ?? [];
    }

    public function setWebhook(string $url): bool
    {
        $this->ensureToken();

        $response = Http::timeout($this->timeout)
            ->asJson()
            ->post("{$this->apiUrl}/bot{$this->botToken}/setWebhook", ['url' => $url]);

        return (bool) ($response->json('ok') ?? false);
    }

    private function ensureToken(): void
    {
        if ($this->botToken === '') {
            throw new RuntimeException('TELEGRAM_BOT_TOKEN is not configured.');
        }
    }
}
