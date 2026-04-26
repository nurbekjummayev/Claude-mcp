<?php

declare(strict_types=1);

use App\Models\AiTask;
use App\Models\ApiToken;
use App\Services\ClaudeCodeService;

it('returns synchronous claude response', function (): void {
    [$token, $plain] = ApiToken::issue('ask', [], 60);

    $mock = Mockery::mock(ClaudeCodeService::class);
    $mock->shouldReceive('ask')
        ->once()
        ->andReturn([
            'response' => 'Hello, world!',
            'raw' => null,
            'tokens' => ['input' => 12, 'output' => 5],
            'cost_usd' => 0.0012,
            'duration_ms' => 1234,
            'model' => 'claude-haiku-4-5',
            'session_id' => null,
        ]);

    app()->instance(ClaudeCodeService::class, $mock);

    $response = $this->withHeaders(['Authorization' => "Bearer {$plain}"])
        ->postJson('/api/v1/ai/ask', [
            'prompt' => 'Say hi',
            'model' => 'claude-haiku-4-5',
        ]);

    $response->assertOk()
        ->assertJson([
            'status' => 'completed',
            'response' => 'Hello, world!',
            'duration_ms' => 1234,
            'tokens' => ['input' => 12, 'output' => 5],
        ])
        ->assertJsonStructure(['task_id', 'cost_usd']);

    expect(AiTask::find($response->json('task_id')))
        ->status->toBe(AiTask::STATUS_COMPLETED);
});

it('records failure when claude throws', function (): void {
    [$token, $plain] = ApiToken::issue('ask-fail', [], 60);

    $mock = Mockery::mock(ClaudeCodeService::class);
    $mock->shouldReceive('ask')->once()->andThrow(new RuntimeException('boom'));

    app()->instance(ClaudeCodeService::class, $mock);

    $response = $this->withHeaders(['Authorization' => "Bearer {$plain}"])
        ->postJson('/api/v1/ai/ask', ['prompt' => 'fail please']);

    $response->assertStatus(502)
        ->assertJson(['status' => 'failed', 'error' => 'boom']);

    expect(AiTask::find($response->json('task_id')))
        ->status->toBe(AiTask::STATUS_FAILED);
});

it('rejects ask without prompt', function (): void {
    [$token, $plain] = ApiToken::issue('ask-v', [], 60);

    $this->withHeaders(['Authorization' => "Bearer {$plain}"])
        ->postJson('/api/v1/ai/ask', [])
        ->assertStatus(422);
});

it('prepends context to prompt when provided', function (): void {
    [$token, $plain] = ApiToken::issue('ask-ctx', [], 60);

    $mock = Mockery::mock(ClaudeCodeService::class);
    $mock->shouldReceive('ask')
        ->once()
        ->withArgs(function (string $prompt) {
            return str_contains($prompt, 'Additional context')
                && str_contains($prompt, 'My ctx')
                && str_contains($prompt, 'My prompt');
        })
        ->andReturn([
            'response' => 'ok',
            'raw' => null,
            'tokens' => ['input' => 1, 'output' => 1],
            'cost_usd' => 0.0,
            'duration_ms' => 10,
            'model' => 'claude-haiku-4-5',
            'session_id' => null,
        ]);

    app()->instance(ClaudeCodeService::class, $mock);

    $this->withHeaders(['Authorization' => "Bearer {$plain}"])
        ->postJson('/api/v1/ai/ask', [
            'prompt' => 'My prompt',
            'context' => 'My ctx',
        ])->assertOk();
});
