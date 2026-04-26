<?php

declare(strict_types=1);

use App\Mcp\Servers\ClaudeMcpServer;
use Laravel\Mcp\Facades\Mcp;

// Stdio transport — invoked by Claude Code via `--mcp-config` (php artisan mcp:start claude).
Mcp::local('claude', ClaudeMcpServer::class);

// HTTP transport — exposes the server at POST /mcp for remote MCP clients.
Mcp::web('/mcp', ClaudeMcpServer::class);
