<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Log;
use RuntimeException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use Throwable;

class ClaudeCodeService
{
    /**
     * @param  list<int>  $retryBackoffMs  Sleep durations between retry attempts.
     */
    public function __construct(
        private readonly string $binaryPath,
        private readonly ?string $apiKey,
        private readonly string $defaultModel,
        private readonly int $timeout,
        private readonly int $maxTurns,
        private readonly int $retryAttempts = 3,
        private readonly array $retryBackoffMs = [1000, 3000, 8000],
    ) {}

    /**
     * Run a Claude Code prompt synchronously.
     *
     * @return array{
     *   response: string,
     *   raw: array<string, mixed>|null,
     *   tokens: array{input: int, output: int},
     *   cost_usd: float,
     *   duration_ms: int,
     *   model: string,
     *   session_id: ?string,
     * }
     */
    public function ask(
        string $prompt,
        ?string $systemPrompt = null,
        ?int $maxTurns = null,
        ?string $model = null,
        ?string $mcpConfigPath = null,
    ): array {
        $modelToUse = $model ?? $this->defaultModel;
        $turns = $maxTurns ?? $this->maxTurns;

        $command = $this->buildCommand($prompt, $systemPrompt, $turns, $modelToUse, $mcpConfigPath);
        $env = $this->buildEnv();

        // Run from a neutral directory so the project's `.mcp.json` (if any)
        // doesn't trigger an interactive trust prompt that hangs `claude -p`.
        $cwd = sys_get_temp_dir();

        $lastError = null;

        for ($attempt = 1; $attempt <= $this->retryAttempts; $attempt++) {
            $start = (int) (microtime(true) * 1000);

            try {
                $process = new Process($command, cwd: $cwd, env: $env);
                $process->setTimeout((float) $this->timeout);
                $process->run();

                $duration = (int) (microtime(true) * 1000) - $start;

                if (! $process->isSuccessful()) {
                    $stderr = trim($process->getErrorOutput());
                    $exit = $process->getExitCode();
                    Log::warning('claude-code: non-zero exit', [
                        'attempt' => $attempt,
                        'exit_code' => $exit,
                        'stderr' => $stderr,
                    ]);
                    throw new RuntimeException("Claude Code exit {$exit}: {$stderr}");
                }

                return $this->parseOutput($process->getOutput(), $duration, $modelToUse);
            } catch (ProcessTimedOutException $e) {
                $lastError = $e;
                Log::warning('claude-code: timeout', ['attempt' => $attempt, 'timeout' => $this->timeout]);
            } catch (Throwable $e) {
                $lastError = $e;
                Log::warning('claude-code: error', ['attempt' => $attempt, 'message' => $e->getMessage()]);
            }

            if ($attempt < $this->retryAttempts) {
                $sleepMs = $this->retryBackoffMs[$attempt - 1] ?? 1000;
                usleep($sleepMs * 1000);
            }
        }

        throw new RuntimeException(
            'Claude Code failed after '.$this->retryAttempts.' attempts: '.($lastError?->getMessage() ?? 'unknown error'),
            0,
            $lastError,
        );
    }

    /**
     * Build the env passed to the Claude CLI subprocess. Claude CLI reads
     * subscription auth from `$HOME/.claude/`, so HOME must point to a real
     * directory readable by the user running the request (php-fpm: www-data).
     *
     * @return array<string, string>
     */
    private function buildEnv(): array
    {
        $env = [];

        $home = getenv('HOME');
        if (! is_string($home) || $home === '') {
            $info = function_exists('posix_geteuid') ? posix_getpwuid(posix_geteuid()) : false;
            $home = is_array($info) && isset($info['dir']) ? (string) $info['dir'] : '/var/www';
        }
        $env['HOME'] = $home;

        if ($this->apiKey !== null && $this->apiKey !== '') {
            $env['ANTHROPIC_API_KEY'] = $this->apiKey;
        }

        return $env;
    }

    /**
     * @return list<string>
     */
    private function buildCommand(
        string $prompt,
        ?string $systemPrompt,
        int $maxTurns,
        string $model,
        ?string $mcpConfigPath,
    ): array {
        $cmd = [
            $this->binaryPath,
            '-p', $prompt,
            '--output-format', 'json',
            '--max-turns', (string) $maxTurns,
            '--model', $model,
        ];

        if ($systemPrompt !== null && $systemPrompt !== '') {
            $cmd[] = '--system-prompt';
            $cmd[] = $systemPrompt;
        }

        if ($mcpConfigPath !== null && $mcpConfigPath !== '') {
            $cmd[] = '--mcp-config';
            $cmd[] = $mcpConfigPath;
            // Only honor the explicit config; ignore project-level .mcp.json.
            $cmd[] = '--strict-mcp-config';
        }

        return $cmd;
    }

    /**
     * @return array{
     *   response: string,
     *   raw: array<string, mixed>|null,
     *   tokens: array{input: int, output: int},
     *   cost_usd: float,
     *   duration_ms: int,
     *   model: string,
     *   session_id: ?string,
     * }
     */
    private function parseOutput(string $output, int $duration, string $model): array
    {
        $output = trim($output);

        $decoded = json_decode($output, true);
        if (! is_array($decoded)) {
            // Not JSON — Claude likely returned raw text. Fall back gracefully.
            return [
                'response' => $output,
                'raw' => null,
                'tokens' => ['input' => 0, 'output' => 0],
                'cost_usd' => 0.0,
                'duration_ms' => $duration,
                'model' => $model,
                'session_id' => null,
            ];
        }

        $response = (string) ($decoded['result'] ?? $decoded['response'] ?? $decoded['text'] ?? '');
        $usage = is_array($decoded['usage'] ?? null) ? $decoded['usage'] : [];
        $tokensIn = (int) ($usage['input_tokens'] ?? $decoded['tokens_input'] ?? 0);
        $tokensOut = (int) ($usage['output_tokens'] ?? $decoded['tokens_output'] ?? 0);

        $cost = isset($decoded['total_cost_usd'])
            ? (float) $decoded['total_cost_usd']
            : $this->calculateCost($model, $tokensIn, $tokensOut);

        $reportedDuration = isset($decoded['duration_ms']) ? (int) $decoded['duration_ms'] : $duration;

        return [
            'response' => $response !== '' ? $response : $output,
            'raw' => $decoded,
            'tokens' => ['input' => $tokensIn, 'output' => $tokensOut],
            'cost_usd' => $cost,
            'duration_ms' => $reportedDuration,
            'model' => $model,
            'session_id' => isset($decoded['session_id']) ? (string) $decoded['session_id'] : null,
        ];
    }

    /**
     * Run a prompt with the local MCP stdio server attached. The MCP config is
     * written to a temp file and removed afterwards.
     *
     * @return array{
     *   response: string,
     *   raw: array<string, mixed>|null,
     *   tokens: array{input: int, output: int},
     *   cost_usd: float,
     *   duration_ms: int,
     *   model: string,
     *   session_id: ?string,
     * }
     */
    public function askWithMcp(
        string $prompt,
        ?string $systemPrompt = null,
        ?int $maxTurns = null,
        ?string $model = null,
    ): array {
        $configPath = $this->writeMcpConfig();

        try {
            return $this->ask(
                prompt: $prompt,
                systemPrompt: $systemPrompt,
                maxTurns: $maxTurns,
                model: $model,
                mcpConfigPath: $configPath,
            );
        } finally {
            if (is_file($configPath)) {
                @unlink($configPath);
            }
        }
    }

    private function writeMcpConfig(): string
    {
        $artisan = base_path('artisan');
        $phpBinary = (string) (PHP_BINARY ?: 'php');

        $config = [
            'mcpServers' => [
                'laravel' => [
                    'command' => $phpBinary,
                    'args' => [$artisan, 'mcp:start', 'claude'],
                ],
            ],
        ];

        $path = tempnam(sys_get_temp_dir(), 'mcp-config-').'.json';
        file_put_contents($path, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return $path;
    }

    public function calculateCost(string $model, int $tokensIn, int $tokensOut): float
    {
        $pricing = config("ai.pricing.$model");
        if (! is_array($pricing)) {
            return 0.0;
        }

        $in = (float) ($pricing['input_per_1m'] ?? 0);
        $out = (float) ($pricing['output_per_1m'] ?? 0);

        return round(($tokensIn / 1_000_000) * $in + ($tokensOut / 1_000_000) * $out, 6);
    }
}
