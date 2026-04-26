<?php

declare(strict_types=1);

use App\Models\ApiToken;
use App\Services\ClaudeCodeService;

it('rejects translate without articles', function (): void {
    [$token, $plain] = ApiToken::issue('t', [], 60);

    $this->withHeaders(['Authorization' => "Bearer {$plain}"])
        ->postJson('/api/v1/ai/translate', [])
        ->assertStatus(422);
});

it('translates articles using mocked claude service', function (): void {
    [$token, $plain] = ApiToken::issue('t2', [], 60);

    $mock = Mockery::mock(ClaudeCodeService::class);
    $mock->shouldReceive('ask')
        ->once()
        ->andReturn([
            'response' => json_encode([
                'translated_articles' => [
                    ['title_uz' => 'Salom dunyo', 'url' => 'https://x.test/1', 'summary_uz' => 'Tarjima'],
                ],
                'telegram_message' => "📰 *Yangi maqolalar*\n\n1. Salom dunyo",
            ]),
            'raw' => null,
            'tokens' => ['input' => 100, 'output' => 50],
            'cost_usd' => 0.01,
            'duration_ms' => 500,
            'model' => 'claude-haiku-4-5',
            'session_id' => null,
        ]);

    app()->instance(ClaudeCodeService::class, $mock);

    $response = $this->withHeaders(['Authorization' => "Bearer {$plain}"])
        ->postJson('/api/v1/ai/translate', [
            'articles' => [
                ['title' => 'Hello world', 'url' => 'https://x.test/1'],
            ],
            'target_language' => 'uz',
            'format' => 'telegram_markdown',
        ]);

    $response->assertOk()
        ->assertJson([
            'status' => 'completed',
            'translated_articles' => [
                ['title_uz' => 'Salom dunyo', 'url' => 'https://x.test/1'],
            ],
        ])
        ->assertJsonStructure(['task_id', 'telegram_message']);
});

it('falls back gracefully when claude returns non-json', function (): void {
    [$token, $plain] = ApiToken::issue('t3', [], 60);

    $mock = Mockery::mock(ClaudeCodeService::class);
    $mock->shouldReceive('ask')
        ->once()
        ->andReturn([
            'response' => 'I am sorry but I refused to follow instructions.',
            'raw' => null,
            'tokens' => ['input' => 50, 'output' => 20],
            'cost_usd' => 0.001,
            'duration_ms' => 300,
            'model' => 'claude-haiku-4-5',
            'session_id' => null,
        ]);

    app()->instance(ClaudeCodeService::class, $mock);

    $response = $this->withHeaders(['Authorization' => "Bearer {$plain}"])
        ->postJson('/api/v1/ai/translate', [
            'articles' => [['title' => 'x', 'url' => 'https://x.test/1']],
        ]);

    $response->assertOk();
    expect($response->json('translated_articles.0.title_uz'))->toBe('x');
});
