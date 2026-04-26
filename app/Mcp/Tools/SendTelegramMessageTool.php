<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Services\TelegramService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Throwable;

#[Description('Send a message to a Telegram chat.')]
class SendTelegramMessageTool extends Tool
{
    public function __construct(private readonly TelegramService $telegram) {}

    public function handle(Request $request): Response
    {
        $chatId = (string) ($request->get('chat_id') ?? '');
        $text = (string) ($request->get('text') ?? '');
        $parseMode = (string) ($request->get('parse_mode') ?? 'Markdown');

        if ($chatId === '' || $text === '') {
            return Response::error('chat_id and text are required');
        }

        try {
            $apiResponse = $this->telegram->sendMessage($chatId, $text, $parseMode);

            return Response::json(['ok' => true, 'response' => $apiResponse]);
        } catch (Throwable $e) {
            return Response::error($e->getMessage());
        }
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'chat_id' => $schema->string()
                ->description('Telegram chat ID (numeric or @channel)')
                ->required(),
            'text' => $schema->string()
                ->description('Message text')
                ->required(),
            'parse_mode' => $schema->string()
                ->description('Telegram parse mode')
                ->enum(['Markdown', 'MarkdownV2', 'HTML'])
                ->default('Markdown'),
        ];
    }
}
