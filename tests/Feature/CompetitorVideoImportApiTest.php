<?php

namespace Tests\Feature;

use App\Jobs\ProcessCompetitorVideoJob;
use App\Models\Admin;
use App\Models\CompetitorVideo;
use App\Models\CompetitorVideoWatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * 竞品短视频导入 API：监控主题读取与桥接入库。
 */
class CompetitorVideoImportApiTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): Admin
    {
        return Admin::query()->create([
            'username' => 'competitor_video_admin',
            'password' => 'secret-123',
            'email' => 'cv@example.com',
            'display_name' => 'Competitor Video',
            'role' => 'admin',
            'status' => 'active',
        ]);
    }

    private function token(Admin $admin, array $scopes): string
    {
        return $admin->createToken('competitor-bridge', $scopes)->plainTextToken;
    }

    public function test_watches_require_read_scope(): void
    {
        $admin = $this->admin();
        $writeOnly = $this->token($admin, ['competitor-videos:write']);

        $this->withHeader('Authorization', 'Bearer '.$writeOnly)
            ->getJson('/api/v1/competitor-video-watches')
            ->assertForbidden();
    }

    public function test_import_accepts_mediacrawler_payload_with_comments(): void
    {
        Queue::fake();
        Storage::fake('local');
        $admin = $this->admin();
        $watch = CompetitorVideoWatch::query()->create([
            'name' => '物流抖音',
            'platform' => 'douyin',
            'collector' => 'mediacrawler',
            'keywords' => ['物流', '货车'],
            'entity_types' => CompetitorVideoWatch::DEFAULT_ENTITY_TYPES,
            'brand_name' => '测试物流',
            'status' => CompetitorVideoWatch::STATUS_ACTIVE,
            'created_by_admin_id' => $admin->id,
        ]);
        $token = $this->token($admin, ['competitor-videos:write']);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'X-Idempotency-Key' => 'mc-douyin-demo-1',
        ])->post('/api/v1/competitor-video-imports', [
            'platform' => 'douyin',
            'external_id' => '751234567890',
            'source_url' => 'https://www.douyin.com/video/751234567890',
            'author' => '货源账号',
            'title' => '回程车找货',
            'caption' => '广州到成都整车',
            'collector' => 'mediacrawler',
            'watch_id' => $watch->id,
            'comments' => json_encode([
                [
                    'id' => 'c1',
                    'parent_id' => null,
                    'author' => '司机甲',
                    'body' => '有货联系13800138000，公司叫华运物流',
                    'published_at' => '2026-10-23T01:00:00Z',
                ],
            ], JSON_UNESCAPED_UNICODE),
            'video' => UploadedFile::fake()->create('demo.mp4', 1024, 'video/mp4'),
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.platform', 'douyin')
            ->assertJsonPath('data.external_id', '751234567890');

        $video = CompetitorVideo::query()->where('external_id', '751234567890')->first();
        $this->assertNotNull($video);
        $this->assertSame(CompetitorVideo::STATUS_TRANSCRIBING, $video->status);
        $this->assertSame(1, $video->comments()->count());
        Queue::assertPushed(ProcessCompetitorVideoJob::class);
    }
}
