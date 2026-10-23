<?php

namespace App\Services\CompetitorVideos;

use App\Models\CompetitorVideo;
use App\Models\CompetitorVideoSegment;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * 转写本地视频，并抽取口播、画面和评论中的字段。
 */
final class CompetitorVideoProcessor
{
    public function __construct(
        private readonly CompetitorVideoMediaClient $media,
        private readonly CompetitorVideoExtractor $extractor,
    ) {}

    public function process(int $videoId): void
    {
        $video = CompetitorVideo::query()->find($videoId);
        if (! $video instanceof CompetitorVideo) {
            return;
        }
        if (in_array($video->status, [CompetitorVideo::STATUS_APPROVED, CompetitorVideo::STATUS_COMPOSING], true)) {
            $this->extractor->extract($video);

            return;
        }

        $hasFile = is_string($video->storage_path) && $video->storage_path !== '' && Storage::disk('local')->exists($video->storage_path);
        if (! $hasFile) {
            $video->forceFill(['status' => CompetitorVideo::STATUS_AWAITING_MEDIA])->save();
            $this->extractor->extract($video);

            return;
        }

        $video->forceFill(['status' => CompetitorVideo::STATUS_TRANSCRIBING, 'error_message' => null])->save();
        $absolute = Storage::disk('local')->path($video->storage_path);
        $speech = null;
        $vision = null;
        $errors = [];
        try {
            $speech = $this->media->transcribe($absolute);
        } catch (Throwable $exception) {
            $errors[] = $exception->getMessage();
        }
        try {
            $vision = $this->media->readFrame($absolute);
        } catch (Throwable $exception) {
            $errors[] = $exception->getMessage();
        }

        if (is_array($speech)) {
            $video->transcript_text = $speech['text'];
            $video->segments()->where('source', CompetitorVideoSegment::SOURCE_SPEECH)->delete();
            foreach ($speech['segments'] as $segment) {
                $video->segments()->create([
                    'source' => CompetitorVideoSegment::SOURCE_SPEECH,
                    'start_ms' => $segment['start_ms'],
                    'end_ms' => $segment['end_ms'],
                    'text' => $segment['text'],
                ]);
            }
        }
        if (is_string($vision)) {
            $video->vision_text = $vision;
            $video->segments()->where('source', CompetitorVideoSegment::SOURCE_VISION)->delete();
            $video->segments()->create([
                'source' => CompetitorVideoSegment::SOURCE_VISION,
                'text' => $vision,
            ]);
        }

        $parsed = is_array($speech) || is_string($vision);
        $video->status = $parsed || trim((string) $video->caption) !== ''
            ? CompetitorVideo::STATUS_AWAITING_REVIEW
            : CompetitorVideo::STATUS_AWAITING_PARSE;
        $video->error_message = $errors === [] ? null : implode(' ', $errors);
        $video->save();
        $this->extractor->extract($video->fresh() ?? $video);
    }
}
