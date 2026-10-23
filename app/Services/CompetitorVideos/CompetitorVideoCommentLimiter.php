<?php

namespace App\Services\CompetitorVideos;

/**
 * 把采集器交来的评论裁到每条视频的上限内。
 */
final class CompetitorVideoCommentLimiter
{
    /**
     * @param  list<array<string, mixed>>  $comments
     * @return list<array<string, mixed>>
     */
    public function limit(array $comments): array
    {
        $topLimit = (int) config('competitor_videos.top_comment_limit');
        $replyLimit = (int) config('competitor_videos.reply_limit');
        $top = [];
        $replies = [];

        foreach ($comments as $comment) {
            $parent = trim((string) ($comment['parent_id'] ?? ''));
            if ($parent === '') {
                if (count($top) < $topLimit) {
                    $top[] = $comment;
                }

                continue;
            }

            $replies[$parent] ??= [];
            if (count($replies[$parent]) < $replyLimit) {
                $replies[$parent][] = $comment;
            }
        }

        $keptIds = [];
        $kept = [];
        foreach ($top as $comment) {
            $id = (string) $comment['id'];
            $keptIds[$id] = true;
            $kept[] = $comment;
        }

        foreach ($replies as $parent => $items) {
            if (! isset($keptIds[$parent])) {
                continue;
            }
            foreach ($items as $comment) {
                $kept[] = $comment;
            }
        }

        return $kept;
    }
}
