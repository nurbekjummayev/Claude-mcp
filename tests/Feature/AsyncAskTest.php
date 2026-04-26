<?php

declare(strict_types=1);

use App\Jobs\ProcessAiRequestJob;
use App\Models\AiTask;
use App\Models\ApiToken;
use Illuminate\Support\Facades\Queue;

it('queues an async job and returns task id', function (): void {
    Queue::fake();

    [$token, $plain] = ApiToken::issue('async-test', [], 60);

    $response = $this->withHeaders(['Authorization' => "Bearer {$plain}"])
        ->postJson('/api/v1/ai/ask-async', [
            'prompt' => 'Translate hello to Uzbek',
            'model' => 'claude-haiku-4-5',
        ]);

    $response->assertStatus(202)
        ->assertJsonStructure(['status', 'task_id', 'check_url'])
        ->assertJson(['status' => 'queued']);

    $taskId = $response->json('task_id');

    expect(AiTask::find($taskId))
        ->not->toBeNull()
        ->status->toBe(AiTask::STATUS_PENDING);

    Queue::assertPushed(ProcessAiRequestJob::class, fn ($job) => $job->task->id === $taskId);
});

it('returns 404 for unknown task id on status', function (): void {
    [$token, $plain] = ApiToken::issue('s', [], 60);

    $this->withHeaders(['Authorization' => "Bearer {$plain}"])
        ->getJson('/api/v1/ai/status/01000000-0000-0000-0000-000000000000')
        ->assertStatus(404);
});

it('returns task details on status when found', function (): void {
    [$token, $plain] = ApiToken::issue('s2', [], 60);

    $task = AiTask::create([
        'prompt' => 'p',
        'model' => 'claude-haiku-4-5',
        'status' => AiTask::STATUS_COMPLETED,
        'response' => 'done',
        'tokens_input' => 10,
        'tokens_output' => 20,
        'cost_usd' => 0.5,
        'duration_ms' => 1234,
        'completed_at' => now(),
    ]);

    $this->withHeaders(['Authorization' => "Bearer {$plain}"])
        ->getJson("/api/v1/ai/status/{$task->id}")
        ->assertOk()
        ->assertJson([
            'task_id' => $task->id,
            'status' => 'completed',
            'response' => 'done',
            'tokens' => ['input' => 10, 'output' => 20],
            'duration_ms' => 1234,
        ]);
});

it('rejects async request without prompt', function (): void {
    [$token, $plain] = ApiToken::issue('s3', [], 60);

    $this->withHeaders(['Authorization' => "Bearer {$plain}"])
        ->postJson('/api/v1/ai/ask-async', [])
        ->assertStatus(422);
});
