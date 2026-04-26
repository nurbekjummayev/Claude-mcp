#!/usr/bin/env bash
set -euo pipefail

# Bootstrap a fresh deployment of the Claude MCP server.
# Usage: bin/setup.sh [n8n-token-name]

cd "$(dirname "$0")/.."

echo "==> Installing composer dependencies..."
composer install --no-interaction --optimize-autoloader --no-dev

echo "==> Ensuring .env exists..."
if [[ ! -f .env ]]; then
  cp .env.example .env
  php artisan key:generate
fi

echo "==> Running migrations..."
php artisan migrate --force

echo "==> Caching config / routes..."
php artisan config:cache
php artisan route:cache

echo "==> Linking storage..."
php artisan storage:link || true

echo "==> Restarting queues (will reload after supervisor signals)..."
php artisan queue:restart

if [[ "${1:-}" != "" ]]; then
  echo "==> Issuing API token: $1"
  php artisan ai:token:create "$1" --rate-limit=120
fi

echo "==> Done."
