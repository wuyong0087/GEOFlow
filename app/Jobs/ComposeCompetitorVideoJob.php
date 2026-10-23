<?php

namespace App\Jobs;

use App\Models\CompetitorVideo;
use App\Models\CompetitorVideoScript;
use App\Services\CompetitorVideos\CompetitorVideoRenderer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * 用已审核脚本合成自有成片。
 */
final class ComposeCompetitorVideoJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 180;

    public function __construct(public readonly int $scriptId) {}

    /** @return list<string> */
    public function tags(): array
    {
        return ['competitor-video-compose', 'competitor-video-script:'.$this->scriptId];
    }

    public function handle(CompetitorVideoRenderer $composer): void
    {
        $script = CompetitorVideoScript::query()->find($this->scriptId);
        if (! $script instanceof CompetitorVideoScript) {
            return;
        }

        $script->video?->forceFill(['status' => CompetitorVideo::STATUS_COMPOSING])->save();
        $composer->compose($script);
        $script->video?->forceFill(['status' => CompetitorVideo::STATUS_APPROVED])->save();
    }
}
