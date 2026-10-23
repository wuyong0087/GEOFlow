<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 一条竞品短视频档案。
 */
class CompetitorVideo extends Model
{
    public const STATUS_INGESTED = 'ingested';

    public const STATUS_AWAITING_MEDIA = 'awaiting_media';

    public const STATUS_TRANSCRIBING = 'transcribing';

    public const STATUS_AWAITING_PARSE = 'awaiting_parse';

    public const STATUS_AWAITING_REVIEW = 'awaiting_review';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_COMPOSING = 'composing';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'competitor_video_watch_id',
        'platform',
        'external_id',
        'source_url',
        'author',
        'title',
        'caption',
        'collector',
        'status',
        'storage_path',
        'content_hash',
        'duration_seconds',
        'file_bytes',
        'transcript_text',
        'vision_text',
        'error_message',
        'imported_on',
        'created_by_admin_id',
    ];

    protected function casts(): array
    {
        return [
            'imported_on' => 'date',
        ];
    }

    public function watch(): BelongsTo
    {
        return $this->belongsTo(CompetitorVideoWatch::class, 'competitor_video_watch_id');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(CompetitorVideoComment::class);
    }

    public function segments(): HasMany
    {
        return $this->hasMany(CompetitorVideoSegment::class);
    }

    public function extractions(): HasMany
    {
        return $this->hasMany(CompetitorVideoExtraction::class);
    }

    public function scripts(): HasMany
    {
        return $this->hasMany(CompetitorVideoScript::class);
    }
}
