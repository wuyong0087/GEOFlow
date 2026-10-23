<?php

namespace App\Services\CompetitorVideos;

use App\Models\CompetitorVideo;
use App\Models\CompetitorVideoExtraction;
use App\Models\CompetitorVideoScript;
use App\Models\CompetitorVideoWatch;
use RuntimeException;

/**
 * 在敏感字段复核通过后生成原创口播脚本。
 */
final class CompetitorVideoScriptWriter
{
    public function __construct(
        private readonly CompetitorVideoChatClient $chat,
        private readonly CompetitorVideoSimilarity $similarity,
    ) {}

    public function write(CompetitorVideo $video): CompetitorVideoScript
    {
        $video->loadMissing(['watch', 'extractions']);
        $pending = $video->extractions->contains(
            fn (CompetitorVideoExtraction $extraction): bool => $extraction->review_status === CompetitorVideoExtraction::REVIEW_PENDING,
        );
        if ($pending) {
            throw new RuntimeException('还有待复核的联系人、号码或报价');
        }

        $watch = $video->watch;
        $brand = $watch instanceof CompetitorVideoWatch ? $watch->brand_name : '本方物流';
        $routes = $watch instanceof CompetitorVideoWatch ? (string) $watch->brand_routes : '';
        $source = trim(implode("\n", array_filter([
            (string) $video->title,
            (string) $video->caption,
            (string) $video->transcript_text,
            (string) $video->vision_text,
        ])));
        $draft = $this->draft($video, $brand, $routes);
        $blocked = $this->similarity->tooSimilar(implode("\n", $draft), $source);
        if ($blocked && $this->chat->hasChatModel()) {
            $draft = $this->fallback($brand, $routes);
            $blocked = $this->similarity->tooSimilar(implode("\n", $draft), $source);
        }

        return CompetitorVideoScript::query()->create([
            'competitor_video_id' => $video->id,
            'voiceover' => $draft['voiceover'],
            'shots' => $draft['shots'],
            'on_screen_text' => $draft['on_screen_text'],
            'cover_title' => $draft['cover_title'],
            'publish_caption' => $draft['publish_caption'],
            'too_similar' => $blocked,
            'status' => $blocked ? CompetitorVideoScript::STATUS_BLOCKED : CompetitorVideoScript::STATUS_READY,
        ]);
    }

    /**
     * @return array{voiceover: string, shots: list<string>, on_screen_text: string, cover_title: string, publish_caption: string}
     */
    private function draft(CompetitorVideo $video, string $brand, string $routes): array
    {
        if ($this->chat->hasChatModel()) {
            $structured = $this->chat->script([
                'brand' => $brand,
                'routes' => $routes,
                'structure' => ['hook', 'pain', 'proof', 'call_to_action'],
                'source_excerpt' => mb_substr(trim((string) $video->caption.' '.$video->transcript_text), 0, 1500),
            ]);
            if (trim((string) ($structured['voiceover'] ?? '')) !== '') {
                return [
                    'voiceover' => (string) $structured['voiceover'],
                    'shots' => array_values(array_map('strval', (array) ($structured['shots'] ?? []))),
                    'on_screen_text' => (string) ($structured['on_screen_text'] ?? $brand),
                    'cover_title' => mb_substr((string) ($structured['cover_title'] ?? $brand), 0, 200),
                    'publish_caption' => (string) ($structured['publish_caption'] ?? $brand),
                ];
            }
        }

        return $this->fallback($brand, $routes);
    }

    /**
     * @return array{voiceover: string, shots: list<string>, on_screen_text: string, cover_title: string, publish_caption: string}
     */
    private function fallback(string $brand, string $routes): array
    {
        $routeText = $routes !== '' ? $routes : '约定线路';
        $voiceover = $brand.'今天说明'.$routeText.'的发车安排。需要对班次和装货时间的，直接联系'.$brand.'调度。';

        return [
            'voiceover' => $voiceover,
            'shots' => ['自有货车出发', '线路卡片', '调度联系方式'],
            'on_screen_text' => $brand.' · '.$routeText,
            'cover_title' => $brand.'发车说明',
            'publish_caption' => $brand.'发布一条'.$routeText.'运力说明。',
        ];
    }
}
