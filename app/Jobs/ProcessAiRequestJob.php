<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\AiTask;
use App\Services\ClaudeCodeService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessAiRequestJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;
    public int $timeout = 600;

    /** @var list<int> */
    public array $backoff = [10, 30, 60];

    public function __construct(public AiTask $task) {}

    public function handle(ClaudeCodeService $claude): void
    {
        $this->task->update(['status' => AiTask::STATUS_PROCESSING]);

        try {
            $result = $claude->askWithMcp(
                prompt: $this->task->prompt,
                systemPrompt: $this->task->system_prompt,
                model: $this->task->model,
            );

            $this->task->update([
                'status' => AiTask::STATUS_COMPLETED,
                'response' => $result['response'],
                'tokens_input' => $result['tokens']['input'],
                'tokens_output' => $result['tokens']['output'],
                'cost_usd' => $result['cost_usd'],
                'duration_ms' => $result['duration_ms'],
                'completed_at' => now(),
            ]);
        } catch (Throwable $e) {
            Log::error('ProcessAiRequestJob failed', [
                'task_id' => $this->task->id,
                'error' => $e->getMessage(),
            ]);

            $this->task->update([
                'status' => AiTask::STATUS_FAILED,
                'error' => $e->getMessage(),
                'completed_at' => now(),
            ]);

            throw $e;
        }
    }

    public function failed(Throwable $exception): void
    {
        $this->task->update([
            'status' => AiTask::STATUS_FAILED,
            'error' => $exception->getMessage(),
            'completed_at' => now(),
        ]);
    }
}
