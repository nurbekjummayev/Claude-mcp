<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Article extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'position',
        'title',
        'title_uz',
        'url',
        'content',
        'summary_uz',
        'digest_date',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'digest_date' => 'date',
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }
}
