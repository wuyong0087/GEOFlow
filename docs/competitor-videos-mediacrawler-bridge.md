# MediaCrawler ↔ GEOFlow 联调说明

MediaCrawler 独立运行，GEOFlow 只接收导入结果。仓库内不嵌入爬虫源码。

## 本机已完成

1. MediaCrawler 依赖：`uv sync`、Playwright Chromium
2. GEOFlow 表迁移：`competitor_video_*`
3. API：`GET /api/v1/competitor-video-watches`、`POST /api/v1/competitor-video-imports`
4. 桥接脚本：[`scripts/mediacrawler_bridge.py`](../scripts/mediacrawler_bridge.py)
5. 样例 jsonl 已导入 2 条抖音档案（含评论）

## 采集（需手机扫码）

```bash
cd /Users/a1234/Documents/Code/AI/GEO/MediaCrawler
source "$HOME/.local/bin/env"
# 已配置关键词：物流,货车,货运,整车,零担,回程车；单次约 10 条；开启评论与媒体
uv run main.py --platform dy --lt qrcode --type search
```

浏览器弹出后用抖音 App 扫码。结果写入 `data/douyin/jsonl/` 与 `data/douyin/media/`。

也可启动 WebUI：

```bash
uv run uvicorn api.main:app --port 8080
# 浏览器打开 http://127.0.0.1:8080
```

## 同步到 GEOFlow

```bash
export GEOFLOW_API_BASE=http://127.0.0.1:18080
export GEOFLOW_API_HOST=ops.guiyunbao.cn   # TrustHosts 需要生产域名
export GEOFLOW_API_TOKEN='你的BearerToken' # 需 scope: competitor-videos:read/write

python3 scripts/mediacrawler_bridge.py --list-watches
python3 scripts/mediacrawler_bridge.py --platform douyin --watch-id 1 --limit 20
```

本机 Docker 经 `127.0.0.1:18080` 访问时，必须带 `GEOFLOW_API_HOST`，否则会返回 404。

## 创建 Token

在 `geoflow-app` 容器内：

```bash
docker exec geoflow-app php artisan tinker --execute='
$admin = App\Models\Admin::query()->where("status","active")->orderBy("id")->first();
echo $admin->createToken("mediacrawler-bridge", ["competitor-videos:read","competitor-videos:write"])->plainTextToken;
'
```

## 注意

- MediaCrawler 为非商业学习许可，仅限学习联调。
- 未配置语音/视觉模型时，档案会停在 `awaiting_media` 或 `awaiting_parse`。
- 单日导入上限：每个监控主题 20 条，每个平台 100 条。
