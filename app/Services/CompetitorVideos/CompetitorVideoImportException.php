<?php

namespace App\Services\CompetitorVideos;

use RuntimeException;

/**
 * 竞品视频导入被上限或字段拒绝。
 */
final class CompetitorVideoImportException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status = 422,
    ) {
        parent::__construct($message);
    }
}
