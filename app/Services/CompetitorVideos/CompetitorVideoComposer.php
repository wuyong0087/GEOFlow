<?php

namespace App\Services\CompetitorVideos;

use App\Models\CompetitorVideoComposition;
use App\Models\CompetitorVideoScript;
use App\Models\CompetitorVideoWatch;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * 用自有画面模板、配音和字幕合成竖版视频。不读取竞品原片。
 */
final class CompetitorVideoComposer implements CompetitorVideoRenderer
{
    public function __construct(private readonly CompetitorVideoMediaClient $media) {}

    public function compose(CompetitorVideoScript $script): CompetitorVideoComposition
    {
        $script->loadMissing('video.watch');
        $composition = CompetitorVideoComposition::query()->create([
            'competitor_video_script_id' => $script->id,
            'status' => CompetitorVideoComposition::STATUS_RENDERING,
        ]);
        $watch = $script->video?->watch;
        $template = $watch instanceof CompetitorVideoWatch ? (string) $watch->template_path : '';
        if ($template === '' || ! Storage::disk('local')->exists($template)) {
            return $this->fail($composition, '缺少自有画面模板');
        }
        if ($script->status !== CompetitorVideoScript::STATUS_READY) {
            return $this->fail($composition, '脚本未通过相似度检查');
        }

        $directory = 'competitor-videos/compositions';
        Storage::disk('local')->makeDirectory($directory);
        $relative = $directory.'/'.$composition->id.'.mp4';
        $output = Storage::disk('local')->path($relative);
        $audio = Storage::disk('local')->path($directory.'/'.$composition->id.'.mp3');
        $spoken = null;
        try {
            $spoken = $this->media->synthesize((string) $script->voiceover, $audio);
        } catch (RuntimeException $exception) {
            return $this->fail($composition, $exception->getMessage());
        }

        $ffmpeg = trim(Process::timeout(5)->run(['which', 'ffmpeg'])->output());
        if ($ffmpeg === '') {
            return $this->fail($composition, 'ffmpeg 不可用');
        }

        $font = $this->font();
        if ($font === null) {
            return $this->fail($composition, '缺少可用的中文字体');
        }

        $command = $this->command($ffmpeg, Storage::disk('local')->path($template), $spoken, $output, (string) $script->on_screen_text, $font);
        $result = Process::timeout(180)->run($command);
        if (! $result->successful() || ! is_file($output)) {
            return $this->fail($composition, '成片合成失败');
        }

        $composition->forceFill([
            'status' => CompetitorVideoComposition::STATUS_READY,
            'storage_path' => $relative,
            'error_message' => null,
        ])->save();

        return $composition;
    }

    /**
     * @return list<string>
     */
    public function command(string $ffmpeg, string $template, ?string $audio, string $output, string $onScreenText, string $font): array
    {
        $drawtext = 'drawtext=fontfile='.$font.':text='.$this->escape($onScreenText).':fontsize=36:fontcolor=white:x=(w-text_w)/2:y=h-120';
        $command = [
            $ffmpeg, '-y',
            '-loop', '1',
            '-i', $template,
        ];
        if (is_string($audio) && is_file($audio)) {
            $command = [...$command, '-i', $audio, '-shortest'];
        } else {
            $command = [...$command, '-f', 'lavfi', '-i', 'anullsrc=channel_layout=stereo:sample_rate=44100', '-t', '8'];
        }

        return [
            ...$command,
            '-vf', 'scale=720:1280:force_original_aspect_ratio=increase,crop=720:1280,'.$drawtext,
            '-c:v', 'libx264',
            '-pix_fmt', 'yuv420p',
            '-c:a', 'aac',
            $output,
        ];
    }

    private function fail(CompetitorVideoComposition $composition, string $message): CompetitorVideoComposition
    {
        $composition->forceFill([
            'status' => CompetitorVideoComposition::STATUS_FAILED,
            'error_message' => $message,
        ])->save();

        return $composition;
    }

    private function font(): ?string
    {
        foreach ([
            '/System/Library/Fonts/PingFang.ttc',
            '/System/Library/Fonts/STHeiti Light.ttc',
            '/usr/share/fonts/opentype/noto/NotoSansCJK-Regular.ttc',
            '/usr/share/fonts/truetype/noto/NotoSansCJK-Regular.ttc',
        ] as $path) {
            if (is_file($path)) {
                return $path;
            }
        }

        return null;
    }

    private function escape(string $text): string
    {
        $text = str_replace(['\\', ':', "'"], ['\\\\', '\\:', ''], $text);

        return "'".mb_substr($text, 0, 40)."'";
    }
}
