<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class ApiToken extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'name',
        'token_hash',
        'permissions',
        'last_used_at',
        'rate_limit_per_minute',
    ];

    protected $hidden = [
        'token_hash',
    ];

    protected function casts(): array
    {
        return [
            'permissions' => 'array',
            'last_used_at' => 'datetime',
            'created_at' => 'datetime',
            'rate_limit_per_minute' => 'integer',
        ];
    }

    /**
     * Issue a new API token. Returns [model, plaintext]. The plaintext is
     * shown to the user once and never persisted in clear form.
     *
     * @param  array<int, string>  $permissions
     * @return array{0: self, 1: string}
     */
    public static function issue(string $name, array $permissions = [], int $rateLimitPerMinute = 60): array
    {
        $plain = Str::random(64);

        $token = self::create([
            'name' => $name,
            'token_hash' => hash('sha256', $plain),
            'permissions' => $permissions,
            'rate_limit_per_minute' => $rateLimitPerMinute,
        ]);

        return [$token, $plain];
    }

    public function hasPermission(string $permission): bool
    {
        $perms = $this->permissions ?? [];

        return $perms === [] || in_array('*', $perms, true) || in_array($permission, $perms, true);
    }
}
