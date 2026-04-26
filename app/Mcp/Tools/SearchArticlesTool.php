<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Models\Article;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\DB;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Search articles by full-text query (Postgres) or substring (other DBs).')]
class SearchArticlesTool extends Tool
{
    public function handle(Request $request): Response
    {
        $query = trim((string) ($request->get('query') ?? ''));
        $limit = max(1, min((int) ($request->get('limit') ?? 5), 20));

        if ($query === '') {
            return Response::json([]);
        }

        if (DB::getDriverName() === 'pgsql') {
            $rows = DB::select(
                'SELECT id, position, title, title_uz, url, summary_uz
                 FROM articles
                 WHERE to_tsvector(\'simple\', title || \' \' || COALESCE(content, \'\'))
                       @@ plainto_tsquery(\'simple\', ?)
                 ORDER BY digest_date DESC
                 LIMIT ?',
                [$query, $limit],
            );

            return Response::json(array_map(fn ($row): array => (array) $row, $rows));
        }

        $results = Article::query()
            ->where('title', 'like', '%'.$query.'%')
            ->orWhere('content', 'like', '%'.$query.'%')
            ->limit($limit)
            ->get(['id', 'position', 'title', 'title_uz', 'url', 'summary_uz'])
            ->map(fn (Article $a): array => $a->toArray())
            ->all();

        return Response::json($results);
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()
                ->description('Search query')
                ->required(),
            'limit' => $schema->integer()
                ->description('Max number of results')
                ->min(1)->max(20)->default(5),
        ];
    }
}
