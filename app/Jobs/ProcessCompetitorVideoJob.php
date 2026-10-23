<?php

namespace App\Jobs;

use App\Models\CompetitorVideo;
use App\Services\CompetitorVideos\CompetitorVideoProcessor;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * 转写一条竞品视频并抽取字段。
 */
final class ProcessCompetitorVideoJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 180;

    public function __construct(public readonly int $competitorVideoId) {}

    public function uniqueId(): string
    {
        return (string) $this->competitorVideoId;
    }

    /** @return list<string> */
    public function tags(): array
    {
        return ['competitor-video', 'competitor-video:'.$this->competitorVideoId];
    }

    public function handle(CompetitorVideoProcessor $processor): void
    {
        $processor->process($this->competitorVideoId);
    }
}
