<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ApiToken;
use Illuminate\Console\Command;

class CreateApiTokenCommand extends Command
{
    protected $signature = 'ai:token:create
        {name : Human-readable label, e.g. "n8n-production"}
        {--rate-limit=60 : Requests per minute allowed for this token}
        {--permission=* : Permission tags (omit for full access)}';

    protected $description = 'Issue a new API token for the AI MCP server';

    public function handle(): int
    {
        $name = (string) $this->argument('name');
        $rateLimit = (int) $this->option('rate-limit');
        $permissions = (array) $this->option('permission');

        if ($name === '') {
            $this->error('Name is required.');

            return self::FAILURE;
        }

        if ($rateLimit < 1) {
            $this->error('Rate limit must be >= 1.');

            return self::FAILURE;
        }

        [$token, $plain] = ApiToken::issue($name, $permissions, $rateLimit);

        $this->info('API token created.');
        $this->line('');
        $this->line("ID:           {$token->id}");
        $this->line("Name:         {$token->name}");
        $this->line("Rate limit:   {$token->rate_limit_per_minute}/min");
        $this->line('Permissions:  '.($permissions === [] ? '(unrestricted)' : implode(', ', $permissions)));
        $this->line('');
        $this->warn('Store this token now — it will not be shown again:');
        $this->line('');
        $this->line($plain);
        $this->line('');

        return self::SUCCESS;
    }
}
