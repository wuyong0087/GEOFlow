<?php

namespace App\Services\CompetitorVideos;

/**
 * 列表展示时隐藏号码中间位。
 */
final class CompetitorVideoMask
{
    public function phone(string $value): string
    {
        return (string) preg_replace('/(1[3-9]\d)\d{4}(\d{4})/u', '$1****$2', $value);
    }
}
