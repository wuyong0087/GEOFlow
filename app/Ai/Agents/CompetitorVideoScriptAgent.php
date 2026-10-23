<?php

namespace App\Ai\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;

/**
 * 按竞品结构为本方品牌重写口播，不复述原句。
 */
final class CompetitorVideoScriptAgent implements Agent, HasStructuredOutput
{
    use Promptable;

    public function instructions(): string
    {
        return '你为本方物流品牌写一条新的短视频口播。只借用给定结构，禁止逐句复述来源文案、口播和评论。不要使用来源中的电话、微信或公司名。事实不足时写成邀约，不要编造运价和时效。';
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'voiceover' => $schema->string()->required(),
            'shots' => $schema->array()->items($schema->string())->required(),
            'on_screen_text' => $schema->string()->required(),
            'cover_title' => $schema->string()->required(),
            'publish_caption' => $schema->string()->required(),
        ];
    }
}
