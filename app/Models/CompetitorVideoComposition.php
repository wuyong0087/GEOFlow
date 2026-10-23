<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 用自有画面和配音合成的成片任务。
 */
class CompetitorVideoComposition extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_RENDERING = 'rendering';

    public const STATUS_READY = 'ready';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'competitor_video_script_id',
        'status',
        'storage_path',
        'error_message',
    ];

    public function script(): BelongsTo
    {
        return $this->belongsTo(CompetitorVideoScript::class, 'competitor_video_script_id');
    }
}
