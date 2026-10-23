<?php

/**
 * 竞品短视频导入上限。采集器本身不在本应用内运行。
 */

return [
    'watch_daily_limit' => 20,
    'platform_daily_limit' => 100,
    'top_comment_limit' => 30,
    'reply_limit' => 10,
    'raw_comment_limit' => 500,
    'max_duration_seconds' => 180,
    'max_file_bytes' => 200 * 1024 * 1024,
];
