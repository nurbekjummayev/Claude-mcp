<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Models\Conversation;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('save_conversation')]
#[Description("Save a turn of a user's conversation for future context.")]
class SaveConversationTool extends Tool
{
    public function handle(Request $request): Response
    {
        $userId = (string) ($request->get('user_id') ?? '');
        $message = (string) ($request->get('message') ?? '');
        $role = (string) ($request->get('role') ?? Conversation::ROLE_USER);

        if ($userId === '' || $message === '') {
            return Response::error('user_id and message are required');
        }

        $normalizedRole = $role === Conversation::ROLE_ASSISTANT
            ? Conversation::ROLE_ASSISTANT
            : Conversation::ROLE_USER;

        $conv = Conversation::create([
            'user_id' => $userId,
            'role' => $normalizedRole,
            'message' => $message,
        ]);

        return Response::json(['id' => $conv->id, 'ok' => true]);
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'user_id' => $schema->string()
                ->description('External user identifier (e.g. Telegram user ID)')
                ->required(),
            'message' => $schema->string()
                ->description('Message content')
                ->required(),
            'role' => $schema->string()
                ->description('Speaker role')
                ->enum(['user', 'assistant'])
                ->default('user'),
        ];
    }
}
