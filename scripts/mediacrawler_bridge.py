#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""把 MediaCrawler 落盘的 jsonl / 视频文件导入 GEOFlow。

MediaCrawler 与 GEOFlow 各自独立部署。本脚本只读下载目录，不读登录态目录。
仅用于学习联调；遵守平台条款与 MediaCrawler 非商业学习许可。
"""

from __future__ import annotations

import argparse
import json
import os
import sys
import time
import uuid
from pathlib import Path
from typing import Any
from urllib import error, parse, request

PLATFORM_ALIASES = {
    "dy": "douyin",
    "douyin": "douyin",
    "xhs": "xiaohongshu",
    "xiaohongshu": "xiaohongshu",
    "zhihu": "zhihu",
}

CONTENT_ID_KEYS = {
    "douyin": "aweme_id",
    "xiaohongshu": "note_id",
    "zhihu": "content_id",
}

URL_KEYS = {
    "douyin": "aweme_url",
    "xiaohongshu": "note_url",
    "zhihu": "content_url",
}


def normalize_platform(raw: str) -> str:
    key = raw.strip().lower()
    if key not in PLATFORM_ALIASES:
        raise SystemExit(f"不支持的平台: {raw}")
    return PLATFORM_ALIASES[key]


def load_jsonl(path: Path) -> list[dict[str, Any]]:
    if not path.exists():
        return []
    rows: list[dict[str, Any]] = []
    with path.open("r", encoding="utf-8") as handle:
        for line in handle:
            line = line.strip()
            if not line:
                continue
            rows.append(json.loads(line))
    return rows


def latest_jsonl(directory: Path, suffix: str) -> Path | None:
    if not directory.exists():
        return None
    matches = sorted(directory.glob(f"*_{suffix}_*.jsonl"), key=lambda item: item.stat().st_mtime)
    return matches[-1] if matches else None


def find_video_file(media_root: Path, external_id: str) -> Path | None:
    folder = media_root / external_id
    if not folder.is_dir():
        return None
    for pattern in ("*.mp4", "*.mov", "*.webm", "*.MP4"):
        files = sorted(folder.glob(pattern))
        if files:
            return files[0]
    for nested in folder.rglob("*"):
        if nested.is_file() and nested.suffix.lower() in {".mp4", ".mov", ".webm"}:
            return nested
    return None


def map_comments(rows: list[dict[str, Any]], external_id: str, content_key: str) -> list[dict[str, Any]]:
    comments: list[dict[str, Any]] = []
    for row in rows:
        if str(row.get(content_key) or "") != external_id:
            continue
        comment_id = str(row.get("comment_id") or "").strip()
        body = str(row.get("content") or "").strip()
        if not comment_id or not body:
            continue
        parent = str(row.get("parent_comment_id") or "").strip()
        if parent in {"", "0"}:
            parent = ""
        published = row.get("create_time")
        published_at = None
        if isinstance(published, (int, float)) and published > 0:
            published_at = time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime(int(published)))
        comments.append(
            {
                "id": comment_id,
                "parent_id": parent or None,
                "author": str(row.get("nickname") or "") or None,
                "body": body,
                "published_at": published_at,
            }
        )
    return comments


def api_request(
    base_url: str,
    token: str,
    method: str,
    path: str,
    *,
    payload: dict[str, Any] | None = None,
    files: dict[str, tuple[str, bytes, str]] | None = None,
    idempotency_key: str | None = None,
    host: str | None = None,
) -> dict[str, Any]:
    url = base_url.rstrip("/") + path
    headers = {
        "Authorization": f"Bearer {token}",
        "Accept": "application/json",
        "User-Agent": "geoflow-mediacrawler-bridge/1.0",
    }
    if host:
        headers["Host"] = host
    if idempotency_key:
        headers["X-Idempotency-Key"] = idempotency_key

    body: bytes | None = None
    if files:
        boundary = f"----GeoFlowBridge{uuid.uuid4().hex}"
        headers["Content-Type"] = f"multipart/form-data; boundary={boundary}"
        chunks: list[bytes] = []
        assert payload is not None
        for key, value in payload.items():
            if value is None:
                continue
            text = value if isinstance(value, str) else json.dumps(value, ensure_ascii=False)
            chunks.append(f"--{boundary}\r\n".encode())
            chunks.append(f'Content-Disposition: form-data; name="{key}"\r\n\r\n'.encode())
            chunks.append(text.encode("utf-8"))
            chunks.append(b"\r\n")
        for field, (filename, content, content_type) in files.items():
            chunks.append(f"--{boundary}\r\n".encode())
            chunks.append(
                (
                    f'Content-Disposition: form-data; name="{field}"; '
                    f'filename="{filename}"\r\n'
                    f"Content-Type: {content_type}\r\n\r\n"
                ).encode()
            )
            chunks.append(content)
            chunks.append(b"\r\n")
        chunks.append(f"--{boundary}--\r\n".encode())
        body = b"".join(chunks)
    elif payload is not None:
        headers["Content-Type"] = "application/json"
        body = json.dumps(payload, ensure_ascii=False).encode("utf-8")

    req = request.Request(url, data=body, headers=headers, method=method)
    try:
        with request.urlopen(req, timeout=120) as response:
            raw = response.read().decode("utf-8")
            return json.loads(raw) if raw else {}
    except error.HTTPError as exc:
        detail = exc.read().decode("utf-8", errors="replace")
        raise SystemExit(f"HTTP {exc.code} {path}: {detail}") from exc


def pick_watch(watches: list[dict[str, Any]], platform: str, watch_id: int | None) -> dict[str, Any] | None:
    if watch_id is not None:
        for watch in watches:
            if int(watch["id"]) == watch_id:
                return watch
        raise SystemExit(f"监控主题 {watch_id} 不存在或未激活")
    for watch in watches:
        if watch.get("platform") == platform and watch.get("collector") == "mediacrawler":
            return watch
    for watch in watches:
        if watch.get("platform") == platform:
            return watch
    return None


def build_payload(platform: str, content: dict[str, Any], comments: list[dict[str, Any]], watch_id: int | None) -> dict[str, Any]:
    content_key = CONTENT_ID_KEYS[platform]
    external_id = str(content.get(content_key) or "").strip()
    if not external_id:
        raise ValueError("缺少外部内容 ID")
    source_url = str(content.get(URL_KEYS[platform]) or "").strip()
    if not source_url:
        if platform == "douyin":
            source_url = f"https://www.douyin.com/video/{external_id}"
        elif platform == "xiaohongshu":
            source_url = f"https://www.xiaohongshu.com/explore/{external_id}"
        else:
            raise ValueError("缺少公开链接")
    return {
        "platform": platform,
        "external_id": external_id,
        "source_url": source_url,
        "author": str(content.get("nickname") or "") or None,
        "title": str(content.get("title") or content.get("desc") or "")[:500] or None,
        "caption": str(content.get("desc") or "") or None,
        "collector": "mediacrawler",
        "watch_id": watch_id,
        "comments": comments,
    }


def sync_platform(
    *,
    data_root: Path,
    platform_dir: str,
    platform: str,
    base_url: str,
    token: str,
    watch: dict[str, Any] | None,
    limit: int,
    dry_run: bool,
    host: str | None,
) -> int:
    jsonl_root = data_root / platform_dir / "jsonl"
    media_root = data_root / platform_dir / "media"
    contents_path = latest_jsonl(jsonl_root, "contents")
    comments_path = latest_jsonl(jsonl_root, "comments")
    if contents_path is None:
        print(f"[skip] 未找到 {platform} contents jsonl: {jsonl_root}", file=sys.stderr)
        return 0

    contents = load_jsonl(contents_path)
    comments = load_jsonl(comments_path) if comments_path else []
    content_key = CONTENT_ID_KEYS[platform]
    imported = 0
    for content in contents:
        if imported >= limit:
            break
        external_id = str(content.get(content_key) or "").strip()
        if not external_id:
            continue
        mapped_comments = map_comments(comments, external_id, content_key)
        payload = build_payload(platform, content, mapped_comments, int(watch["id"]) if watch else None)
        video_path = find_video_file(media_root, external_id)
        print(
            f"[ready] {platform}/{external_id} comments={len(mapped_comments)} "
            f"video={'yes' if video_path else 'no'}"
        )
        if dry_run:
            imported += 1
            continue

        idem = f"mc-{platform}-{external_id}-{int(time.time() // 86400)}"
        if video_path is not None:
            mime = {
                ".mp4": "video/mp4",
                ".mov": "video/quicktime",
                ".webm": "video/webm",
            }.get(video_path.suffix.lower(), "video/mp4")
            form_payload = {
                key: (json.dumps(value, ensure_ascii=False) if key == "comments" else value)
                for key, value in payload.items()
                if value is not None
            }
            result = api_request(
                base_url,
                token,
                "POST",
                "/api/v1/competitor-video-imports",
                payload=form_payload,
                files={"video": (video_path.name, video_path.read_bytes(), mime)},
                idempotency_key=idem,
                host=host,
            )
        else:
            result = api_request(
                base_url,
                token,
                "POST",
                "/api/v1/competitor-video-imports",
                payload=payload,
                idempotency_key=idem,
                host=host,
            )
        data = result.get("data") or {}
        print(f"[ok] id={data.get('id')} status={data.get('status')} external_id={data.get('external_id')}")
        imported += 1
    return imported


def main() -> None:
    parser = argparse.ArgumentParser(description="同步 MediaCrawler 结果到 GEOFlow")
    parser.add_argument(
        "--data-root",
        default=os.environ.get("MEDIACRAWLER_DATA_ROOT", "/Users/a1234/Documents/Code/AI/GEO/MediaCrawler/data"),
        help="MediaCrawler data 目录",
    )
    parser.add_argument(
        "--base-url",
        default=os.environ.get("GEOFLOW_API_BASE", "http://127.0.0.1:18080"),
        help="GEOFlow API 根地址",
    )
    parser.add_argument(
        "--host",
        default=os.environ.get("GEOFLOW_API_HOST", "ops.guiyunbao.cn"),
        help="信任 Host（本机 Docker 经 127.0.0.1 访问时必须带生产域名）",
    )
    parser.add_argument("--token", default=os.environ.get("GEOFLOW_API_TOKEN", ""), help="Bearer Token")
    parser.add_argument("--platform", default="douyin", help="dy|douyin|xhs|xiaohongshu|zhihu")
    parser.add_argument("--watch-id", type=int, default=None)
    parser.add_argument("--limit", type=int, default=20)
    parser.add_argument("--dry-run", action="store_true")
    parser.add_argument("--list-watches", action="store_true")
    args = parser.parse_args()

    if not args.token:
        raise SystemExit("请通过 --token 或 GEOFLOW_API_TOKEN 提供 API Token")

    platform = normalize_platform(args.platform)
    platform_dir = {
        "douyin": "douyin",
        "xiaohongshu": "xhs",
        "zhihu": "zhihu",
    }[platform]
    host = args.host.strip() or None

    watches_response = api_request(
        args.base_url,
        args.token,
        "GET",
        "/api/v1/competitor-video-watches",
        host=host,
    )
    watches = list((watches_response.get("data") or {}).get("watches") or [])
    if args.list_watches:
        print(json.dumps(watches, ensure_ascii=False, indent=2))
        return

    watch = pick_watch(watches, platform, args.watch_id)
    if watch is None:
        print("[warn] 未匹配到监控主题，将以 watch_id=null 导入", file=sys.stderr)

    count = sync_platform(
        data_root=Path(args.data_root),
        platform_dir=platform_dir,
        platform=platform,
        base_url=args.base_url,
        token=args.token,
        watch=watch,
        limit=args.limit,
        dry_run=args.dry_run,
        host=host,
    )
    print(f"[done] imported={count} platform={platform}")


if __name__ == "__main__":
    main()
