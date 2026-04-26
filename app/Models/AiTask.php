<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AiTask extends Model
{
    use HasUuids;

    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    protected $table = 'ai_tasks';

    public $timestamps = false;

    protected $fillable = [
        'prompt',
        'system_prompt',
        'context',
        'status',
        'response',
        'error',
        'model',
        'tokens_input',
        'tokens_output',
        'cost_usd',
        'duration_ms',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'context' => 'array',
            'tokens_input' => 'integer',
            'tokens_output' => 'integer',
            'cost_usd' => 'decimal:6',
            'duration_ms' => 'integer',
            'created_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }
}
