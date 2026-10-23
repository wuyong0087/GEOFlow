<?php

namespace App\Services\CompetitorVideos;

use App\Models\CompetitorVideoComposition;
use App\Models\CompetitorVideoScript;

/**
 * 把已审核脚本渲染成自有成片。
 */
interface CompetitorVideoRenderer
{
    public function compose(CompetitorVideoScript $script): CompetitorVideoComposition;
}
