<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 竞品短视频下的一条评论或回复。
 */
class CompetitorVideoComment extends Model
{
    protected $fillable = [
        'competitor_video_id',
        'platform_comment_id',
        'parent_platform_comment_id',
        'author',
        'body',
        'published_at',
        'matched',
    ];

    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
            'matched' => 'boolean',
        ];
    }

    public function video(): BelongsTo
    {
        return $this->belongsTo(CompetitorVideo::class, 'competitor_video_id');
    }
}
