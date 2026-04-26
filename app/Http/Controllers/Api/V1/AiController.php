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

        $task = AiTask::create([
            'prompt' => $prompt,
            'system_prompt' => $data['system'] ?? null,
            'model' => $model,
            'status' => AiTask::STATUS_PROCESSING,
        ]);

        try {
            $result = $this->claude->ask(
                prompt: $prompt,
                systemPrompt: $data['system'] ?? null,
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
Translate the following article titles to {$lang} and write a 1-sentence summary for each.
Return STRICT JSON in this exact shape:

{
  "translated_articles": [
    {"title_uz": "...", "url": "...", "summary_uz": "..."}
  ],
  "telegram_message": "..."
}

For "telegram_message", produce a {$format} formatted digest with all articles.
Do not include any text outside the JSON.

Articles:
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
            return [
                'translated_articles' => (array) $decoded['translated_articles'],
                'telegram_message' => (string) $decoded['telegram_message'],
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

    private function composePrompt(string $prompt, ?string $context): string
    {
        if ($context === null || $context === '') {
            return $prompt;
        }

        return "Additional context:\n{$context}\n\n---\n\n{$prompt}";
    }
}
