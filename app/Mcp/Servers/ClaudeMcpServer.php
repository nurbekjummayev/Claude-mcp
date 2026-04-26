<?php

declare(strict_types=1);

namespace App\Mcp\Servers;

use App\Mcp\Tools\GetArticleContentTool;
use App\Mcp\Tools\GetArticlesTool;
use App\Mcp\Tools\GetWeatherTool;
use App\Mcp\Tools\SaveConversationTool;
use App\Mcp\Tools\SearchArticlesTool;
use App\Mcp\Tools\SendTelegramMessageTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

#[Name('Claude MCP Laravel')]
#[Version('1.0.0')]
#[Instructions('Tools to query articles, fetch remote content, save conversations, and send Telegram messages.')]
class ClaudeMcpServer extends Server
{
    protected array $tools = [
        GetArticlesTool::class,
        SearchArticlesTool::class,
        GetArticleContentTool::class,
        SendTelegramMessageTool::class,
        SaveConversationTool::class,
        GetWeatherTool::class,
    ];

    protected array $resources = [
        //
    ];

    protected array $prompts = [
        //
    ];
}
