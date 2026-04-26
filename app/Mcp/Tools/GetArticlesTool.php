<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Models\Article;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Carbon;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('get_articles')]
#[Description('Get articles from the local database, optionally filtered by date or keyword.')]
class GetArticlesTool extends Tool
{
    public function handle(Request $request): Response
    {
        $date = (string) ($request->get('date') ?? 'today');
        $limit = max(1, min((int) ($request->get('limit') ?? 10), 50));
        $keyword = $request->get('keyword');

        $resolvedDate = $this->resolveDate($date);

        $query = Article::query()->orderBy('position');

        if ($resolvedDate !== null) {
            $query->whereDate('digest_date', $resolvedDate);
        }

        if (is_string($keyword) && $keyword !== '') {
            $query->where('title', 'like', '%'.$keyword.'%');
        }

        $articles = $query->limit($limit)->get()->map(fn (Article $a): array => [
            'id' => $a->id,
            'position' => $a->position,
            'title' => $a->title,
            'title_uz' => $a->title_uz,
            'url' => $a->url,
            'summary_uz' => $a->summary_uz,
            'digest_date' => $a->digest_date?->toDateString(),
        ])->all();

        return Response::json($articles);
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'date' => $schema->string()
                ->description("'today', 'yesterday', or YYYY-MM-DD")
                ->default('today'),
            'limit' => $schema->integer()
                ->description('Max number of articles to return')
                ->min(1)->max(50)->default(10),
            'keyword' => $schema->string()
                ->description('Optional substring filter applied to the title'),
        ];
    }

    private function resolveDate(string $date): ?string
    {
        $date = strtolower(trim($date));

        return match (true) {
            $date === '' => null,
            $date === 'today' => Carbon::today()->toDateString(),
            $date === 'yesterday' => Carbon::yesterday()->toDateString(),
            (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) => $date,
            default => null,
        };
    }
}
