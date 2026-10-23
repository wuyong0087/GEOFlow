<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 口播或画面文字的一个片段。
 */
class CompetitorVideoSegment extends Model
{
    public const SOURCE_SPEECH = 'speech';

    public const SOURCE_VISION = 'vision';

    protected $fillable = [
        'competitor_video_id',
        'source',
        'start_ms',
        'end_ms',
        'text',
    ];

    public function video(): BelongsTo
    {
        return $this->belongsTo(CompetitorVideo::class, 'competitor_video_id');
    }
}
