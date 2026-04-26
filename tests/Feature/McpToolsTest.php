<?php

declare(strict_types=1);

use App\Mcp\Servers\ClaudeMcpServer;
use App\Mcp\Tools\GetArticleContentTool;
use App\Mcp\Tools\GetArticlesTool;
use App\Mcp\Tools\SaveConversationTool;
use App\Mcp\Tools\SearchArticlesTool;
use App\Models\Article;
use App\Models\Conversation;

it('returns articles for today', function (): void {
    Article::create(['position' => 1, 'title' => 'Hello A', 'url' => 'https://x.test/a', 'digest_date' => today()]);
    Article::create(['position' => 2, 'title' => 'Hello B', 'url' => 'https://x.test/b', 'digest_date' => today()]);
    Article::create(['position' => 3, 'title' => 'Old',     'url' => 'https://x.test/c', 'digest_date' => today()->subDay()]);

    ClaudeMcpServer::tool(GetArticlesTool::class, ['date' => 'today', 'limit' => 10])
        ->assertOk()
        ->assertHasNoErrors()
        ->assertSee('Hello A')
        ->assertSee('Hello B')
        ->assertDontSee('Old');
});

it('filters articles by keyword', function (): void {
    Article::create(['position' => 1, 'title' => 'PHP performance', 'url' => 'https://x.test/php']);
    Article::create(['position' => 2, 'title' => 'Go basics', 'url' => 'https://x.test/go']);

    ClaudeMcpServer::tool(GetArticlesTool::class, ['keyword' => 'PHP'])
        ->assertOk()
        ->assertSee('PHP performance')
        ->assertDontSee('Go basics');
});

it('searches articles by query (sqlite fallback)', function (): void {
    Article::create(['position' => 1, 'title' => 'How to write Laravel', 'url' => 'https://x.test/1']);
    Article::create(['position' => 2, 'title' => 'Symfony tips', 'url' => 'https://x.test/2']);

    ClaudeMcpServer::tool(SearchArticlesTool::class, ['query' => 'Laravel'])
        ->assertOk()
        ->assertSee('How to write Laravel')
        ->assertDontSee('Symfony tips');
});

it('saves a conversation entry', function (): void {
    ClaudeMcpServer::tool(SaveConversationTool::class, [
        'user_id' => 'user-42',
        'message' => 'Hello there',
        'role' => 'user',
    ])
        ->assertOk()
        ->assertSee('"ok":true');

    expect(Conversation::query()->where('user_id', 'user-42')->count())->toBe(1);
});

it('rejects invalid url for get_article_content', function (): void {
    ClaudeMcpServer::tool(GetArticleContentTool::class, ['url' => 'not-a-url'])
        ->assertHasErrors();
});

it('exposes the expected tools on the server', function (): void {
    $reflection = new ReflectionClass(ClaudeMcpServer::class);
    $tools = $reflection->getProperty('tools')->getDefaultValue();

    expect($tools)->toBe([
        GetArticlesTool::class,
        SearchArticlesTool::class,
        GetArticleContentTool::class,
        \App\Mcp\Tools\SendTelegramMessageTool::class,
        SaveConversationTool::class,
        \App\Mcp\Tools\GetWeatherTool::class,
    ]);
});
