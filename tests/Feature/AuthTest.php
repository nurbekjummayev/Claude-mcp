<?php

declare(strict_types=1);

use App\Models\ApiToken;

it('rejects unauthenticated requests', function (): void {
    $this->postJson('/api/v1/ai/ask', ['prompt' => 'hi'])
        ->assertStatus(401)
        ->assertJson(['error' => 'Unauthorized']);
});

it('rejects requests with invalid token', function (): void {
    $this->withHeaders(['Authorization' => 'Bearer not-a-real-token'])
        ->postJson('/api/v1/ai/ask', ['prompt' => 'hi'])
        ->assertStatus(401);
});

it('rejects status endpoint without token', function (): void {
    $this->getJson('/api/v1/ai/status/some-uuid')
        ->assertStatus(401);
});

it('updates last_used_at on successful auth', function (): void {
    [$token, $plain] = ApiToken::issue('test', [], 60);

    expect($token->last_used_at)->toBeNull();

    $this->withHeaders(['Authorization' => "Bearer {$plain}"])
        ->getJson('/api/v1/ai/status/missing-uuid')
        ->assertStatus(404);

    expect($token->fresh()->last_used_at)->not->toBeNull();
});

it('enforces rate limit', function (): void {
    [$token, $plain] = ApiToken::issue('limited', [], 3);

    for ($i = 0; $i < 3; $i++) {
        $this->withHeaders(['Authorization' => "Bearer {$plain}"])
            ->getJson('/api/v1/ai/status/missing-uuid')
            ->assertStatus(404);
    }

    $this->withHeaders(['Authorization' => "Bearer {$plain}"])
        ->getJson('/api/v1/ai/status/missing-uuid')
        ->assertStatus(429)
        ->assertJson(['error' => 'Rate limit exceeded']);
});
