<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\AskRequest;
use App\Http\Requests\TranslateRequest;
use App\Jobs\ProcessAiRequestJob;
use App\Models\AiTask;
use App\Services\ClaudeCodeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class AiController
{
    public function __construct(
        private readonly ClaudeCodeService $claude,
    ) {}

    public function ask(AskRequest $request): JsonResponse
    {
        $data = $request->validated();
        $prompt = $this->composePrompt((string) $data['prompt'], $data['context'] ?? null);
        $model = (string) ($data['model'] ?? config('ai.claude.default_model'));
        $systemPrompt = (string) ($data['system'] ?? $this->defaultSystemPrompt());

        $task = AiTask::create([
            'prompt' => $prompt,
            'system_prompt' => $systemPrompt,
            'model' => $model,
            'status' => AiTask::STATUS_PROCESSING,
        ]);

        try {
            $result = $this->claude->askWithMcp(
                prompt: $prompt,
                systemPrompt: $systemPrompt,
                maxTurns: isset($data['max_turns']) ? (int) $data['max_turns'] : null,
                model: $model,
            );

            $task->update([
                'status' => AiTask::STATUS_COMPLETED,
                'response' => $result['response'],
                'tokens_input' => $result['tokens']['input'],
                'tokens_output' => $result['tokens']['output'],
                'cost_usd' => $result['cost_usd'],
                'duration_ms' => $result['duration_ms'],
                'completed_at' => now(),
            ]);

            return response()->json([
                'status' => 'completed',
                'response' => $result['response'],
                'duration_ms' => $result['duration_ms'],
                'tokens' => [
                    'input' => $result['tokens']['input'],
                    'output' => $result['tokens']['output'],
                ],
                'cost_usd' => $result['cost_usd'],
                'task_id' => $task->id,
            ]);
        } catch (Throwable $e) {
            Log::error('ai.ask failed', [
                'task_id' => $task->id,
                'error' => $e->getMessage(),
            ]);

            $task->update([
                'status' => AiTask::STATUS_FAILED,
                'error' => $e->getMessage(),
                'completed_at' => now(),
            ]);

            return response()->json([
                'status' => 'failed',
                'error' => $e->getMessage(),
                'task_id' => $task->id,
            ], 502);
        }
    }

    public function askAsync(AskRequest $request): JsonResponse
    {
        $data = $request->validated();
        $prompt = $this->composePrompt((string) $data['prompt'], $data['context'] ?? null);
        $model = (string) ($data['model'] ?? config('ai.claude.default_model'));

        $task = AiTask::create([
            'prompt' => $prompt,
            'system_prompt' => $data['system'] ?? null,
            'model' => $model,
            'status' => AiTask::STATUS_PENDING,
        ]);

        ProcessAiRequestJob::dispatch($task);

        return response()->json([
            'status' => 'queued',
            'task_id' => $task->id,
            'check_url' => "/api/v1/ai/status/{$task->id}",
        ], 202);
    }

    public function status(Request $request, string $taskId): JsonResponse
    {
        $task = AiTask::find($taskId);

        if ($task === null) {
            return response()->json(['error' => 'Task not found'], 404);
        }

        return response()->json([
            'task_id' => $task->id,
            'status' => $task->status,
            'response' => $task->response,
            'error' => $task->error,
            'tokens' => [
                'input' => $task->tokens_input,
                'output' => $task->tokens_output,
            ],
            'cost_usd' => (float) $task->cost_usd,
            'duration_ms' => $task->duration_ms,
            'created_at' => $task->created_at?->toIso8601String(),
            'completed_at' => $task->completed_at?->toIso8601String(),
        ]);
    }

    public function translate(TranslateRequest $request): JsonResponse
    {
        $data = $request->validated();
        $articles = $data['articles'];
        $target = (string) ($data['target_language'] ?? 'uz');
        $format = (string) ($data['format'] ?? 'telegram_markdown');
        $model = (string) ($data['model'] ?? config('ai.claude.default_model'));

        $prompt = $this->buildTranslatePrompt($articles, $target, $format);

        $task = AiTask::create([
            'prompt' => $prompt,
            'model' => $model,
            'status' => AiTask::STATUS_PROCESSING,
            'context' => ['articles' => $articles, 'target_language' => $target, 'format' => $format],
        ]);

        try {
            $result = $this->claude->ask(
                prompt: $prompt,
                systemPrompt: 'You are a precise translator. Output STRICT JSON only.',
                model: $model,
            );

            $payload = $this->extractTranslateJson($result['response'], $articles);

            $task->update([
                'status' => AiTask::STATUS_COMPLETED,
                'response' => $result['response'],
                'tokens_input' => $result['tokens']['input'],
                'tokens_output' => $result['tokens']['output'],
                'cost_usd' => $result['cost_usd'],
                'duration_ms' => $result['duration_ms'],
                'completed_at' => now(),
            ]);

            return response()->json([
                'status' => 'completed',
                'translated_articles' => $payload['translated_articles'],
                'telegram_message' => $payload['telegram_message'],
                'task_id' => $task->id,
            ]);
        } catch (Throwable $e) {
            Log::error('ai.translate failed', [
                'task_id' => $task->id,
                'error' => $e->getMessage(),
            ]);

            $task->update([
                'status' => AiTask::STATUS_FAILED,
                'error' => $e->getMessage(),
                'completed_at' => now(),
            ]);

            return response()->json([
                'status' => 'failed',
                'error' => $e->getMessage(),
                'task_id' => $task->id,
            ], 502);
        }
    }

    /**
     * @param  array<int, array{title: string, url: string}>  $articles
     */
    private function buildTranslatePrompt(array $articles, string $target, string $format): string
    {
        $list = '';
        foreach ($articles as $i => $a) {
            $n = $i + 1;
            $list .= "{$n}. Title: {$a['title']}\n   URL: {$a['url']}\n";
        }

        $lang = match ($target) {
            'uz' => "O'zbek tili",
            'ru' => 'Russian',
            default => 'English',
        };

        return <<<PROMPT
Translate each article title to {$lang} and write a 2-3 sentence summary for each.

URL RULES (CRITICAL):
- Copy each URL EXACTLY as given. Character-for-character. Do not modify, encode, or wrap.
- The "url" field must contain a plain URL, NOT markdown link syntax.

TELEGRAM MESSAGE FORMAT (CRITICAL — follow EXACTLY):

The "telegram_message" must consist ONLY of numbered article blocks. One block per article,
separated by a single blank line. Each block is exactly three lines:

  <NUMBER_EMOJI> <Translated title>
  <2-3 sentence summary in {$lang}>
  [O'qish](<EXACT URL FROM INPUT>)

Use these emoji numbers for the first 10 items, in order:
1️⃣ 2️⃣ 3️⃣ 4️⃣ 5️⃣ 6️⃣ 7️⃣ 8️⃣ 9️⃣ 🔟
For items 11 and beyond use plain digits with a dot: 11. 12. 13. ...

DO NOT add ANY of: a header line, section dividers ("Texnik:", "Kasbiy:"), author lists,
publication lists, "n8n" footer, extra emojis, decorative separators. Only the numbered
article blocks. Plain text — no bold, no italics, no quote blocks.

Concrete example for 2 articles:

1️⃣ PHP-da 50 million qator hujjatni serverga yuk bermay paginate qilish
Bu maqolada katta hajmdagi ma'lumotlarni keyset paginatsiyasi orqali samarali sahifalash
texnikalari ko'rib chiqilgan. Muallif memory va CPU yukini kamaytirish uchun aniq SQL
patternlar va PHP kod misollarini taqdim etadi.
[O'qish](https://medium.com/example/article-1)

2️⃣ Laravel 13 yangi imkoniyatlari haqida
Yangi versiyaning eng muhim xususiyatlari, performance yaxshilanishlari va breaking
change'lar batafsil ko'rib chiqilgan. Migratsiya jarayoni uchun amaliy maslahatlar.
[O'qish](https://medium.com/example/article-2)

Return STRICT JSON in this exact shape, with no text outside the JSON:

{
  "translated_articles": [
    {"title_uz": "...", "url": "<EXACT URL FROM INPUT>", "summary_uz": "..."}
  ],
  "telegram_message": "<formatted digest exactly as described>"
}

Articles to translate:
{$list}
PROMPT;
    }

    /**
     * @param  array<int, array{title: string, url: string}>  $originals
     * @return array{translated_articles: array<int, array<string, mixed>>, telegram_message: string}
     */
    private function extractTranslateJson(string $response, array $originals): array
    {
        // Claude may wrap JSON in fences; strip them.
        $clean = trim($response);
        $clean = (string) preg_replace('/^```(?:json)?\s*|\s*```$/m', '', $clean);

        $decoded = json_decode($clean, true);

        if (is_array($decoded) && isset($decoded['translated_articles'], $decoded['telegram_message'])) {
            $articles = $this->normalizeTranslatedArticles((array) $decoded['translated_articles'], $originals);
            $telegram = $this->fixUrlsInText((string) $decoded['telegram_message'], $originals);

            return [
                'translated_articles' => $articles,
                'telegram_message' => $telegram,
            ];
        }

        // Fallback: return originals plus the raw response so the caller has something.
        return [
            'translated_articles' => array_map(fn (array $a): array => [
                'title_uz' => $a['title'],
                'url' => $a['url'],
                'summary_uz' => null,
            ], $originals),
            'telegram_message' => $response,
        ];
    }

    /**
     * Defensive: replace each translated article's URL with the corresponding
     * original URL by index. Claude sometimes mangles URLs even when prompted
     * not to (e.g. wraps them in markdown link syntax inside the field).
     *
     * @param  array<int, mixed>  $translated
     * @param  array<int, array{title: string, url: string}>  $originals
     * @return array<int, array<string, mixed>>
     */
    private function normalizeTranslatedArticles(array $translated, array $originals): array
    {
        $result = [];

        foreach ($translated as $i => $item) {
            $entry = is_array($item) ? $item : [];
            if (isset($originals[$i])) {
                $entry['url'] = $originals[$i]['url'];
            }
            $result[] = $entry;
        }

        return $result;
    }

    /**
     * Replace any mangled URLs in the telegram_message string with their
     * original counterparts, matched by domain+path prefix.
     *
     * @param  array<int, array{title: string, url: string}>  $originals
     */
    private function fixUrlsInText(string $text, array $originals): string
    {
        foreach ($originals as $a) {
            $original = $a['url'];

            // Common Claude mangling: wraps the host in a markdown link.
            // e.g. https://example.com/1 → https://[example.com](http://example.com)/1
            $host = (string) parse_url($original, PHP_URL_HOST);
            if ($host === '') {
                continue;
            }

            // Match the mangled pattern and replace with the original URL.
            $mangled = '~https?://\['.preg_quote($host, '~').'\]\(https?://'.preg_quote($host, '~').'\)([^\s)\]]*)~i';
            $text = (string) preg_replace_callback($mangled, function (array $m) use ($original, $host): string {
                $path = (string) parse_url($original, PHP_URL_PATH);
                $query = (string) parse_url($original, PHP_URL_QUERY);
                $tail = $path.($query !== '' ? '?'.$query : '');

                // Only swap if the mangled tail matches the original path tail.
                return str_starts_with($m[1], $tail) || $m[1] === $tail
                    ? $original.substr($m[1], strlen($tail))
                    : $original;
            }, $text);
        }

        return $text;
    }

    private function composePrompt(string $prompt, ?string $context): string
    {
        if ($context === null || $context === '') {
            return $prompt;
        }

        return "Additional context:\n{$context}\n\n---\n\n{$prompt}";
    }

    /**
     * Default system prompt: positions Claude as a general-purpose assistant
     * (not just a code assistant) and tells it the local MCP tools exist so
     * it picks them up for things like weather, articles, Telegram, etc.
     */
    private function defaultSystemPrompt(): string
    {
        return <<<'PROMPT'
You are a helpful general-purpose assistant accessible via API. Reply in the
user's language. You have access to local MCP tools for things like weather
lookups, fetching articles from a database, searching articles, fetching
remote URLs, sending Telegram messages, and saving conversations. Use those
tools whenever they help answer the user's question — do not refuse a
question just because it asks for real-time data; check the tools first.
Be concise.
PROMPT;
    }
}
