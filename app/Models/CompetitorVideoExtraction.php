<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 从口播、画面或评论抽出的一条字段。
 */
class CompetitorVideoExtraction extends Model
{
    public const FIELD_COMPANY = 'company';

    public const FIELD_CONTACT = 'contact';

    public const FIELD_PHONE = 'phone';

    public const FIELD_CARGO = 'cargo';

    public const FIELD_ROUTE = 'route';

    public const FIELD_PRICE = 'price';

    public const REVIEW_PENDING = 'pending';

    public const REVIEW_APPROVED = 'approved';

    public const REVIEW_REJECTED = 'rejected';

    /** @var list<string> */
    public const SENSITIVE_FIELDS = [
        self::FIELD_CONTACT,
        self::FIELD_PHONE,
        self::FIELD_PRICE,
    ];

    protected $fillable = [
        'competitor_video_id',
        'competitor_video_comment_id',
        'field',
        'value',
        'evidence',
        'confidence',
        'review_status',
    ];

    public function video(): BelongsTo
    {
        return $this->belongsTo(CompetitorVideo::class, 'competitor_video_id');
    }

    public function comment(): BelongsTo
    {
        return $this->belongsTo(CompetitorVideoComment::class, 'competitor_video_comment_id');
    }
}
