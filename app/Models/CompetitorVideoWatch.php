<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 物流类竞品短视频监控主题。
 */
class CompetitorVideoWatch extends Model
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_LOGIN_REQUIRED = 'login_required';

    public const STATUS_PAUSED = 'paused';

    /** @var list<string> */
    public const PLATFORMS = ['douyin', 'xiaohongshu', 'zhihu'];

    /** @var list<string> */
    public const COLLECTORS = ['manual', 'mediacrawler', 'f2'];

    /** @var list<string> */
    public const DEFAULT_ENTITY_TYPES = ['company', 'contact', 'phone', 'cargo'];

    protected $fillable = [
        'name',
        'platform',
        'collector',
        'keywords',
        'entity_types',
        'lexicon',
        'brand_name',
        'brand_routes',
        'template_path',
        'status',
        'daily_imported_on',
        'daily_imported_count',
        'last_imported_at',
        'created_by_admin_id',
    ];

    protected function casts(): array
    {
        return [
            'keywords' => 'array',
            'entity_types' => 'array',
            'lexicon' => 'array',
            'daily_imported_on' => 'date',
            'last_imported_at' => 'datetime',
        ];
    }

    public function videos(): HasMany
    {
        return $this->hasMany(CompetitorVideo::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'created_by_admin_id');
    }
}
