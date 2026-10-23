<?php

namespace App\Services\CompetitorVideos;

use App\Models\CompetitorVideo;
use App\Models\CompetitorVideoComment;
use App\Models\CompetitorVideoExtraction;
use App\Models\CompetitorVideoWatch;

/**
 * 用规则、词表和对话模型从文本中抽出公司、联系人、号码和货物。
 */
final class CompetitorVideoExtractor
{
    public function __construct(private readonly CompetitorVideoChatClient $chat) {}

    public function extract(CompetitorVideo $video): void
    {
        $video->loadMissing(['watch', 'comments']);
        $watch = $video->watch;
        $types = $watch instanceof CompetitorVideoWatch
            ? array_values(array_intersect((array) $watch->entity_types, CompetitorVideoWatch::DEFAULT_ENTITY_TYPES))
            : CompetitorVideoWatch::DEFAULT_ENTITY_TYPES;
        if ($types === []) {
            $types = CompetitorVideoWatch::DEFAULT_ENTITY_TYPES;
        }

        $rows = [];
        $corpus = trim(implode("\n", array_filter([
            (string) $video->title,
            (string) $video->caption,
            (string) $video->transcript_text,
            (string) $video->vision_text,
        ])));
        $rows = array_merge($rows, $this->fromText($corpus, null, $types, $watch));
        foreach ($video->comments as $comment) {
            if ($comment instanceof CompetitorVideoComment) {
                $rows = array_merge($rows, $this->fromText((string) $comment->body, $comment, $types, $watch));
            }
        }

        $rows = array_merge($rows, $this->fromModel($video, $types));
        $seen = [];
        foreach ($rows as $row) {
            $key = $row['field'].'|'.$row['value'].'|'.($row['competitor_video_comment_id'] ?? '');
            if (isset($seen[$key]) || trim((string) $row['value']) === '') {
                continue;
            }
            $seen[$key] = true;
            $existing = CompetitorVideoExtraction::query()
                ->where('competitor_video_id', $video->id)
                ->where('field', $row['field'])
                ->where('value', $row['value'])
                ->exists();
            if ($existing) {
                continue;
            }
            CompetitorVideoExtraction::query()->create($row + ['competitor_video_id' => $video->id]);
        }

        $matchedCommentIds = CompetitorVideoExtraction::query()
            ->where('competitor_video_id', $video->id)
            ->whereNotNull('competitor_video_comment_id')
            ->pluck('competitor_video_comment_id');
        CompetitorVideoComment::query()
            ->where('competitor_video_id', $video->id)
            ->whereIn('id', $matchedCommentIds)
            ->update(['matched' => true]);
    }

    /**
     * @param  list<string>  $types
     * @return list<array<string, mixed>>
     */
    private function fromText(string $text, ?CompetitorVideoComment $comment, array $types, ?CompetitorVideoWatch $watch): array
    {
        if (trim($text) === '') {
            return [];
        }

        $rows = [];
        $commentId = $comment?->id;
        if (in_array('phone', $types, true) && preg_match_all('/1[3-9]\d{9}/u', $text, $phones) > 0) {
            foreach (array_unique($phones[0]) as $phone) {
                $rows[] = $this->row('phone', $phone, $text, $commentId, 90);
            }
        }
        if (in_array('contact', $types, true) && preg_match_all('/(?:微信|vx|v信)[:：\s]*([A-Za-z][-_A-Za-z0-9]{4,19})/iu', $text, $contacts) > 0) {
            foreach (array_unique($contacts[1]) as $contact) {
                $rows[] = $this->row('contact', $contact, $text, $commentId, 80);
            }
        }
        if (preg_match_all('/(\d+(?:\.\d+)?)\s*元/u', $text, $prices) > 0) {
            foreach (array_unique($prices[0]) as $price) {
                $rows[] = $this->row('price', $price, $text, $commentId, 70);
            }
        }

        $lexicon = is_array($watch?->lexicon) ? $watch->lexicon : [];
        foreach (['company' => 'companies', 'cargo' => 'cargo'] as $field => $lexiconKey) {
            if (! in_array($field, $types, true)) {
                continue;
            }
            foreach ((array) ($lexicon[$lexiconKey] ?? []) as $term) {
                $term = trim((string) $term);
                if (mb_strlen($term) >= 2 && mb_strpos($text, $term) !== false) {
                    $rows[] = $this->row($field, $term, $text, $commentId, 75);
                }
            }
        }

        return $rows;
    }

    /**
     * @param  list<string>  $types
     * @return list<array<string, mixed>>
     */
    private function fromModel(CompetitorVideo $video, array $types): array
    {
        if (! $this->chat->hasChatModel()) {
            return [];
        }

        $comments = [];
        foreach ($video->comments as $comment) {
            if ($comment instanceof CompetitorVideoComment) {
                $comments[] = [
                    'id' => (string) $comment->platform_comment_id,
                    'body' => (string) $comment->body,
                ];
            }
        }
        $structured = $this->chat->extract([
            'entity_types' => $types,
            'title' => (string) $video->title,
            'caption' => (string) $video->caption,
            'transcript' => (string) $video->transcript_text,
            'vision' => (string) $video->vision_text,
            'comments' => $comments,
        ]);
        $commentIds = $video->comments->mapWithKeys(
            fn (CompetitorVideoComment $comment): array => [(string) $comment->platform_comment_id => $comment->id],
        );
        $rows = [];
        foreach ((array) ($structured['items'] ?? []) as $item) {
            if (! is_array($item) || ! in_array((string) ($item['field'] ?? ''), ['company', 'contact', 'phone', 'cargo', 'route', 'price'], true)) {
                continue;
            }
            $platformCommentId = trim((string) ($item['comment_id'] ?? ''));
            $rows[] = $this->row(
                (string) $item['field'],
                (string) ($item['value'] ?? ''),
                (string) ($item['evidence'] ?? ''),
                $platformCommentId !== '' ? $commentIds->get($platformCommentId) : null,
                max(1, min(100, (int) ($item['confidence'] ?? 50))),
            );
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    private function row(string $field, string $value, string $evidence, mixed $commentId, int $confidence): array
    {
        $sensitive = in_array($field, CompetitorVideoExtraction::SENSITIVE_FIELDS, true);

        return [
            'field' => $field,
            'value' => mb_substr(trim($value), 0, 500),
            'evidence' => mb_substr(trim($evidence), 0, 2000),
            'competitor_video_comment_id' => is_numeric($commentId) ? (int) $commentId : null,
            'confidence' => $confidence,
            'review_status' => $sensitive ? CompetitorVideoExtraction::REVIEW_PENDING : CompetitorVideoExtraction::REVIEW_APPROVED,
        ];
    }
}
