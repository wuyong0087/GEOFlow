<?php

namespace App\Services\CompetitorVideos;

use App\Ai\Agents\CompetitorVideoExtractionAgent;
use App\Ai\Agents\CompetitorVideoScriptAgent;
use App\Models\AiModel;
use App\Services\GeoFlow\AiUsageQuotaService;
use App\Support\GeoFlow\ApiKeyCrypto;
use App\Support\GeoFlow\OpenAiRuntimeProvider;
use Laravel\Ai\Contracts\Agent;
use RuntimeException;
use Throwable;

/**
 * 用已配置的对话模型做结构化抽取和脚本重写。
 */
final class CompetitorVideoChatClient
{
    public function __construct(
        private readonly ApiKeyCrypto $crypto,
        private readonly AiUsageQuotaService $quota,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function extract(array $payload): array
    {
        return $this->prompt(new CompetitorVideoExtractionAgent, $payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function script(array $payload): array
    {
        return $this->prompt(new CompetitorVideoScriptAgent, $payload);
    }

    public function hasChatModel(): bool
    {
        return $this->chatModel() instanceof AiModel;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function prompt(Agent $agent, array $payload): array
    {
        $model = $this->chatModel();
        if (! $model instanceof AiModel) {
            return [];
        }

        $reservation = $this->quota->reserveModel($model);
        if ($reservation === null) {
            throw new RuntimeException('对话模型已达到今日调用上限');
        }

        try {
            $baseUrl = OpenAiRuntimeProvider::resolveChatBaseUrl((string) $model->api_url);
            $key = $this->crypto->decrypt((string) $model->getRawOriginal('api_key'));
            $provider = OpenAiRuntimeProvider::registerProvider(
                'competitor_videos',
                OpenAiRuntimeProvider::resolveChatDriver($baseUrl, (string) $model->model_id),
                $baseUrl,
                $key,
            );
            $prompt = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $response = $agent->prompt($prompt, [], $provider, (string) $model->model_id, 120);
            $this->quota->recordModelSuccess($reservation);

            return is_array($response->structured) ? $response->structured : [];
        } catch (Throwable $exception) {
            $this->quota->releaseModel($reservation);

            throw new RuntimeException('对话模型调用失败', 0, $exception);
        }
    }

    private function chatModel(): ?AiModel
    {
        $model = AiModel::query()
            ->where('status', 'active')
            ->where(function ($query): void {
                $query->where('model_type', 'chat')->orWhereNull('model_type');
            })
            ->orderBy('failover_priority')
            ->first();

        return $model instanceof AiModel ? $model : null;
    }
}
