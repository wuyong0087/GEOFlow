<?php

namespace App\Services\CompetitorVideos;

use App\Jobs\ProcessCompetitorVideoJob;
use App\Models\CompetitorVideo;
use App\Models\CompetitorVideoComment;
use App\Models\CompetitorVideoWatch;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * 接收桥接程序或后台提交的视频、链接和评论。
 */
final class CompetitorVideoImportService
{
    public function __construct(
        private readonly CompetitorVideoCommentLimiter $comments,
        private readonly CompetitorVideoMediaClient $media,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function import(array $payload, ?UploadedFile $file, ?int $adminId): CompetitorVideo
    {
        $platform = (string) $payload['platform'];
        $lock = Cache::lock('competitor-video-import:'.$platform, 20);
        $lock->block(5);

        try {
            return DB::transaction(function () use ($payload, $file, $adminId, $platform): CompetitorVideo {
                $watch = $this->watch($payload['watch_id'] ?? null);
                $externalId = $this->externalId($payload);
                $existing = CompetitorVideo::query()
                    ->where('platform', $platform)
                    ->where('external_id', $externalId)
                    ->lockForUpdate()
                    ->first();
                if ($existing instanceof CompetitorVideo) {
                    $this->storeComments($existing, (array) ($payload['comments'] ?? []));
                    if ($file instanceof UploadedFile && $existing->content_hash !== $this->hashFile($file)) {
                        $this->storeFile($existing, $file);
                        $existing->status = CompetitorVideo::STATUS_TRANSCRIBING;
                        $existing->save();
                        ProcessCompetitorVideoJob::dispatch($existing->id)->afterCommit();
                    } elseif ($existing->status === CompetitorVideo::STATUS_AWAITING_MEDIA || $existing->status === CompetitorVideo::STATUS_AWAITING_REVIEW) {
                        ProcessCompetitorVideoJob::dispatch($existing->id)->afterCommit();
                    }

                    return $existing->fresh() ?? $existing;
                }

                $this->assertCapacity($watch, $platform);
                $video = CompetitorVideo::query()->create([
                    'competitor_video_watch_id' => $watch?->id,
                    'platform' => $platform,
                    'external_id' => $externalId,
                    'source_url' => (string) $payload['source_url'],
                    'author' => $this->nullableString($payload['author'] ?? null),
                    'title' => $this->nullableString($payload['title'] ?? null),
                    'caption' => $this->nullableString($payload['caption'] ?? null),
                    'collector' => (string) ($payload['collector'] ?? 'manual'),
                    'status' => $file instanceof UploadedFile ? CompetitorVideo::STATUS_TRANSCRIBING : CompetitorVideo::STATUS_AWAITING_MEDIA,
                    'imported_on' => now()->toDateString(),
                    'created_by_admin_id' => $adminId,
                ]);
                if ($file instanceof UploadedFile) {
                    $this->storeFile($video, $file);
                }
                $this->storeComments($video, (array) ($payload['comments'] ?? []));
                $this->recordCapacity($watch);
                ProcessCompetitorVideoJob::dispatch($video->id)->afterCommit();

                return $video->fresh() ?? $video;
            });
        } finally {
            $lock->release();
        }
    }

    private function watch(mixed $watchId): ?CompetitorVideoWatch
    {
        if ($watchId === null || $watchId === '') {
            return null;
        }

        $watch = CompetitorVideoWatch::query()->whereKey((int) $watchId)->lockForUpdate()->first();
        if (! $watch instanceof CompetitorVideoWatch || $watch->status !== CompetitorVideoWatch::STATUS_ACTIVE) {
            throw new CompetitorVideoImportException('watch_unavailable', '监控主题不存在或已暂停');
        }

        return $watch;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function externalId(array $payload): string
    {
        $explicit = trim((string) ($payload['external_id'] ?? ''));
        if ($explicit !== '') {
            return mb_substr($explicit, 0, 120);
        }

        return substr(hash('sha256', (string) $payload['platform'].'|'.(string) $payload['source_url']), 0, 32);
    }

    private function assertCapacity(?CompetitorVideoWatch $watch, string $platform): void
    {
        $today = now()->toDateString();
        if ($watch instanceof CompetitorVideoWatch) {
            $count = $watch->daily_imported_on?->toDateString() === $today ? (int) $watch->daily_imported_count : 0;
            if ($count >= (int) config('competitor_videos.watch_daily_limit')) {
                throw new CompetitorVideoImportException('watch_daily_limit', '该监控主题今日导入已达上限', 429);
            }
        }

        $platformCount = CompetitorVideo::query()
            ->where('platform', $platform)
            ->whereDate('imported_on', $today)
            ->count();
        if ($platformCount >= (int) config('competitor_videos.platform_daily_limit')) {
            throw new CompetitorVideoImportException('platform_daily_limit', '该平台今日导入已达上限', 429);
        }
    }

    private function recordCapacity(?CompetitorVideoWatch $watch): void
    {
        if (! $watch instanceof CompetitorVideoWatch) {
            return;
        }

        $today = now()->toDateString();
        $count = $watch->daily_imported_on?->toDateString() === $today ? (int) $watch->daily_imported_count : 0;
        $watch->forceFill([
            'daily_imported_on' => $today,
            'daily_imported_count' => $count + 1,
            'last_imported_at' => now(),
        ])->save();
    }

    /**
     * @param  list<mixed>  $comments
     */
    private function storeComments(CompetitorVideo $video, array $comments): void
    {
        $normalized = [];
        foreach ($comments as $comment) {
            if (! is_array($comment)) {
                continue;
            }
            $id = trim((string) ($comment['id'] ?? ''));
            $body = trim((string) ($comment['body'] ?? ''));
            if ($id === '' || $body === '') {
                continue;
            }
            $normalized[] = [
                'id' => mb_substr($id, 0, 120),
                'parent_id' => trim((string) ($comment['parent_id'] ?? '')),
                'author' => $this->nullableString($comment['author'] ?? null),
                'body' => mb_substr($body, 0, 5000),
                'published_at' => $comment['published_at'] ?? null,
            ];
        }

        foreach ($this->comments->limit($normalized) as $comment) {
            $publishedAt = null;
            if (is_string($comment['published_at']) && $comment['published_at'] !== '') {
                $publishedAt = Carbon::parse($comment['published_at']);
            }
            CompetitorVideoComment::query()->firstOrCreate(
                [
                    'competitor_video_id' => $video->id,
                    'platform_comment_id' => $comment['id'],
                ],
                [
                    'parent_platform_comment_id' => $comment['parent_id'] !== '' ? mb_substr($comment['parent_id'], 0, 120) : null,
                    'author' => $comment['author'],
                    'body' => $comment['body'],
                    'published_at' => $publishedAt,
                ],
            );
        }
    }

    private function storeFile(CompetitorVideo $video, UploadedFile $file): void
    {
        if ($file->getSize() > (int) config('competitor_videos.max_file_bytes')) {
            throw new CompetitorVideoImportException('file_too_large', '视频超过 200MB');
        }

        $hash = $this->hashFile($file);
        $extension = strtolower($file->getClientOriginalExtension() ?: 'mp4');
        if (! in_array($extension, ['mp4', 'mov', 'webm'], true)) {
            throw new CompetitorVideoImportException('file_type', '只接受 mp4、mov 或 webm');
        }

        $path = $file->storeAs('competitor-videos', $video->id.'-'.$hash.'.'.$extension, 'local');
        $absolute = Storage::disk('local')->path((string) $path);
        $duration = $this->media->probeDuration($absolute);
        if ($duration !== null && $duration > (int) config('competitor_videos.max_duration_seconds')) {
            Storage::disk('local')->delete((string) $path);
            throw new CompetitorVideoImportException('file_too_long', '视频超过 3 分钟');
        }

        $video->forceFill([
            'storage_path' => $path,
            'content_hash' => $hash,
            'duration_seconds' => $duration,
            'file_bytes' => $file->getSize(),
        ])->save();
    }

    private function hashFile(UploadedFile $file): string
    {
        $path = $file->getRealPath();

        return is_string($path) ? hash_file('sha256', $path) : hash('sha256', (string) $file->getContent());
    }

    private function nullableString(mixed $value): ?string
    {
        $text = trim((string) $value);

        return $text === '' ? null : mb_substr($text, 0, 500);
    }
}
