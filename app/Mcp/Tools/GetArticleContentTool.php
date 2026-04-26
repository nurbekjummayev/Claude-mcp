<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Http;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Throwable;

#[Name('get_article_content')]
#[Description('Fetch the full article content from a remote URL. Returns plain text body (truncated to 50k chars).')]
class GetArticleContentTool extends Tool
{
    public function handle(Request $request): Response
    {
        $url = (string) ($request->get('url') ?? '');

        if (! preg_match('#^https?://#i', $url)) {
            return Response::error('invalid URL');
        }

        try {
            $response = Http::timeout(20)
                ->withUserAgent('Claude-MCP-Server/1.0')
                ->get($url);
        } catch (Throwable $e) {
            return Response::error($e->getMessage());
        }

        if (! $response->successful()) {
            return Response::error('HTTP '.$response->status());
        }

        $html = $response->body();
        $text = trim((string) preg_replace(
            ['#<script\b[^>]*>.*?</script>#is', '#<style\b[^>]*>.*?</style>#is', '#<[^>]+>#'],
            ['', '', ' '],
            $html,
        ));
        $text = (string) preg_replace('/\s+/', ' ', $text);

        return Response::text(mb_substr($text, 0, 50_000));
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'url' => $schema->string()
                ->description('Article URL (must start with http:// or https://)')
                ->required(),
        ];
    }
}
