<?php

namespace App\Services\CompetitorVideos;

/**
 * 判断原创脚本是否连续复用了来源文本。
 */
final class CompetitorVideoSimilarity
{
    public function tooSimilar(string $script, string $source): bool
    {
        $script = $this->compact($script);
        $source = $this->compact($source);
        $length = mb_strlen($source);
        if ($length < 12 || $script === '') {
            return false;
        }

        for ($offset = 0; $offset <= $length - 12; $offset += 4) {
            $slice = mb_substr($source, $offset, 12);
            if (mb_strpos($script, $slice) !== false) {
                return true;
            }
        }

        return false;
    }

    private function compact(string $value): string
    {
        $collapsed = preg_replace('/\s+/u', '', $value);

        return is_string($collapsed) ? $collapsed : '';
    }
}
