<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\ApiToken;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

class VerifyApiToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $plain = $request->bearerToken();

        if ($plain === null || $plain === '') {
            return $this->unauthorized();
        }

        $hash = hash('sha256', $plain);
        $apiToken = ApiToken::query()->where('token_hash', $hash)->first();

        if ($apiToken === null) {
            return $this->unauthorized();
        }

        $key = "rate_limit:{$apiToken->id}";
        $count = (int) Cache::increment($key);

        if ($count === 1) {
            // Cache::increment() doesn't set TTL on first hit; pin it to 60s.
            Cache::put($key, 1, 60);
        }

        if ($count > $apiToken->rate_limit_per_minute) {
            return new JsonResponse(['error' => 'Rate limit exceeded'], 429, [
                'Retry-After' => '60',
                'X-RateLimit-Limit' => (string) $apiToken->rate_limit_per_minute,
            ]);
        }

        $apiToken->forceFill(['last_used_at' => now()])->save();
        $request->setUserResolver(fn () => null);
        $request->attributes->set('api_token', $apiToken);

        return $next($request);
    }

    private function unauthorized(): JsonResponse
    {
        return new JsonResponse(['error' => 'Unauthorized'], 401);
    }
}
