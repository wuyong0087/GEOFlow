<?php

namespace App\Services\CompetitorVideos;

use App\Models\AiModel;
use App\Support\GeoFlow\ApiKeyCrypto;
use App\Support\GeoFlow\OpenAiRuntimeProvider;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * 读取已配置的语音、视觉和配音模型。没有对应模型时返回空，不改用对话模型。
 */
final class CompetitorVideoMediaClient
{
    public function __construct(
        private readonly ApiKeyCrypto $crypto,
        private readonly Factory $http,
    ) {}

    /**
     * @return array{text: string, segments: list<array{start_ms: int|null, end_ms: int|null, text: string}>}|null
     */
    public function transcribe(string $absolutePath): ?array
    {
        $model = $this->model('speech');
        if (! $model instanceof AiModel || ! is_file($absolutePath)) {
            return null;
        }

        $response = $this->http->withToken($this->key($model))
            ->timeout(120)
            ->attach('file', fopen($absolutePath, 'r'), basename($absolutePath))
            ->post($this->endpoint($model, '/audio/transcriptions'), [
                'model' => (string) $model->model_id,
                'response_format' => 'verbose_json',
            ]);
        if (! $response->successful()) {
            throw new RuntimeException('语音识别失败');
        }

        $segments = [];
        foreach ((array) $response->json('segments') as $segment) {
            if (! is_array($segment)) {
                continue;
            }
            $text = trim((string) ($segment['text'] ?? ''));
            if ($text === '') {
                continue;
            }
            $segments[] = [
                'start_ms' => isset($segment['start']) ? (int) round(((float) $segment['start']) * 1000) : null,
                'end_ms' => isset($segment['end']) ? (int) round(((float) $segment['end']) * 1000) : null,
                'text' => $text,
            ];
        }
        $text = trim((string) $response->json('text'));
        if ($text === '' && $segments === []) {
            return null;
        }

        return ['text' => $text, 'segments' => $segments];
    }

    /**
     * 从视频抽出一帧后交给视觉模型读取画面文字。
     */
    public function readFrame(string $absoluteVideoPath): ?string
    {
        $model = $this->model('vision');
        $frame = $this->frame($absoluteVideoPath);
        if (! $model instanceof AiModel || $frame === null) {
            return null;
        }

        $encoded = base64_encode((string) file_get_contents($frame));
        @unlink($frame);
        $response = $this->http->withToken($this->key($model))
            ->timeout(120)
            ->post($this->endpoint($model, '/chat/completions'), [
                'model' => (string) $model->model_id,
                'messages' => [[
                    'role' => 'user',
                    'content' => [
                        ['type' => 'text', 'text' => '只摘录画面上看得见的公司、线路、货物、电话和价格，不要补充画面外的信息。'],
                        ['type' => 'image_url', 'image_url' => ['url' => 'data:image/jpeg;base64,'.$encoded]],
                    ],
                ]],
            ]);
        if (! $response->successful()) {
            throw new RuntimeException('画面文字识别失败');
        }

        $text = trim((string) data_get($response->json(), 'choices.0.message.content'));

        return $text === '' ? null : $text;
    }

    /**
     * 把口播稿合成为音频文件，返回绝对路径。
     */
    public function synthesize(string $voiceover, string $targetPath): ?string
    {
        $model = $this->model('tts');
        if (! $model instanceof AiModel) {
            return null;
        }

        $response = $this->http->withToken($this->key($model))
            ->timeout(120)
            ->post($this->endpoint($model, '/audio/speech'), [
                'model' => (string) $model->model_id,
                'input' => mb_substr($voiceover, 0, 2000),
                'voice' => 'alloy',
            ]);
        if (! $response->successful()) {
            throw new RuntimeException('配音合成失败');
        }

        file_put_contents($targetPath, $response->body());

        return $targetPath;
    }

    public function probeDuration(string $absolutePath): ?int
    {
        $ffprobe = $this->binary('ffprobe');
        if ($ffprobe === null || ! is_file($absolutePath)) {
            return null;
        }

        $result = Process::timeout(30)->run([$ffprobe, '-v', 'error', '-show_entries', 'format=duration', '-of', 'csv=p=0', $absolutePath]);
        if (! $result->successful()) {
            return null;
        }

        $duration = (float) trim($result->output());

        return $duration > 0 ? (int) ceil($duration) : null;
    }

    private function frame(string $absoluteVideoPath): ?string
    {
        $ffmpeg = $this->binary('ffmpeg');
        if ($ffmpeg === null || ! is_file($absoluteVideoPath)) {
            return null;
        }

        $target = tempnam(sys_get_temp_dir(), 'competitor-frame-');
        if (! is_string($target)) {
            return null;
        }
        $jpeg = $target.'.jpg';
        @unlink($target);
        $result = Process::timeout(30)->run([$ffmpeg, '-y', '-ss', '1', '-i', $absoluteVideoPath, '-frames:v', '1', $jpeg]);

        return $result->successful() && is_file($jpeg) ? $jpeg : null;
    }

    private function model(string $type): ?AiModel
    {
        $model = AiModel::query()
            ->where('status', 'active')
            ->where('model_type', $type)
            ->orderBy('failover_priority')
            ->first();

        return $model instanceof AiModel ? $model : null;
    }

    private function key(AiModel $model): string
    {
        $key = $this->crypto->decrypt((string) $model->getRawOriginal('api_key'));
        if ($key === '') {
            throw new RuntimeException('模型密钥不可用');
        }

        return $key;
    }

    private function endpoint(AiModel $model, string $suffix): string
    {
        $base = rtrim(OpenAiRuntimeProvider::resolveChatBaseUrl((string) $model->api_url), '/');
        if ($base === '') {
            throw new RuntimeException('模型地址不可用');
        }

        return $base.$suffix;
    }

    private function binary(string $name): ?string
    {
        $result = Process::timeout(5)->run(['which', $name]);
        $path = trim($result->output());

        return $result->successful() && $path !== '' ? $path : null;
    }
}
