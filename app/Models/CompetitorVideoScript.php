<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 按竞品结构重写后的原创口播脚本。
 */
class CompetitorVideoScript extends Model
{
    public const STATUS_READY = 'ready';

    public const STATUS_BLOCKED = 'blocked';

    protected $fillable = [
        'competitor_video_id',
        'voiceover',
        'shots',
        'on_screen_text',
        'cover_title',
        'publish_caption',
        'too_similar',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'shots' => 'array',
            'too_similar' => 'boolean',
        ];
    }

    public function video(): BelongsTo
    {
        return $this->belongsTo(CompetitorVideo::class, 'competitor_video_id');
    }

    public function compositions(): HasMany
    {
        return $this->hasMany(CompetitorVideoComposition::class);
    }
}
