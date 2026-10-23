<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ApiException;
use App\Http\Requests\Api\ImportCompetitorVideoRequest;
use App\Models\CompetitorVideoWatch;
use App\Services\Api\IdempotencyService;
use App\Services\CompetitorVideos\CompetitorVideoImportException;
use App\Services\CompetitorVideos\CompetitorVideoImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 供外部桥接程序读取监控主题并交回视频与评论。
 */
final class CompetitorVideoImportController extends BaseApiController
{
    public function watches(Request $request): JsonResponse
    {
        $watches = CompetitorVideoWatch::query()
            ->where('status', CompetitorVideoWatch::STATUS_ACTIVE)
            ->orderBy('id')
            ->get()
            ->map(fn (CompetitorVideoWatch $watch): array => [
                'id' => $watch->id,
                'name' => $watch->name,
                'platform' => $watch->platform,
                'collector' => $watch->collector,
                'keywords' => $watch->keywords,
                'entity_types' => $watch->entity_types,
                'status' => $watch->status,
            ])
            ->all();

        return $this->success($request, ['watches' => $watches]);
    }

    public function store(ImportCompetitorVideoRequest $request, CompetitorVideoImportService $imports): JsonResponse
    {
        return IdempotencyService::executeJson(
            $request,
            'POST /competitor-video-imports',
            function () use ($request, $imports): JsonResponse {
                try {
                    $video = $imports->import(
                        $request->safe()->except(['video']),
                        $request->file('video'),
                        $this->executionAdmin($request)->id,
                    );
                } catch (CompetitorVideoImportException $exception) {
                    throw new ApiException($exception->errorCode, $exception->getMessage(), $exception->status);
                }

                return $this->success($request, [
                    'id' => $video->id,
                    'platform' => $video->platform,
                    'external_id' => $video->external_id,
                    'status' => $video->status,
                ], 201);
            },
        );
    }
}
