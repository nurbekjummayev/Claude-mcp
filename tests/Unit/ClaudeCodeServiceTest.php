<?php

declare(strict_types=1);

use App\Services\ClaudeCodeService;

function makeService(): ClaudeCodeService
{
    return new ClaudeCodeService(
        binaryPath: '/usr/bin/true',
        apiKey: 'sk-test',
        defaultModel: 'claude-sonnet-4-5',
        timeout: 30,
        maxTurns: 5,
    );
}

it('calculates cost from pricing config', function (): void {
    $svc = makeService();

    $cost = $svc->calculateCost('claude-sonnet-4-5', 1_000_000, 500_000);

    // sonnet-4-5: $3/1M in + $15/1M out = 3 + 7.5 = 10.5
    expect($cost)->toBe(10.5);
});

it('returns zero cost for unknown model', function (): void {
    $svc = makeService();

    expect($svc->calculateCost('unknown-model', 100, 100))->toBe(0.0);
});
