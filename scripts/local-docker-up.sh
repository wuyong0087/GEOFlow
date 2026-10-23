#!/usr/bin/env bash
# GEOFlow 本机 Docker 开发环境一键启动
# 国内网络访问 Docker Hub 超时时，可先走镜像加速预拉取：
#   GEOFLOW_DOCKER_MIRROR=docker.m.daocloud.io ./scripts/local-docker-up.sh
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

# 默认 DaoCloud 公共加速；设为空字符串可禁用预拉取
MIRROR="${GEOFLOW_DOCKER_MIRROR-docker.m.daocloud.io}"

if ! docker info >/dev/null 2>&1; then
  echo "错误：无法连接 Docker。请先打开 Docker Desktop，再重试。" >&2
  exit 1
fi

if [[ ! -f .env ]]; then
  cp .env.example .env
  echo "已从 .env.example 创建 .env"
fi

# shellcheck disable=SC1091
set -a
# 仅加载镜像相关变量（避免 source 整个 .env 的复杂展开）
eval "$(grep -E '^(PHP_CLI_IMAGE|COMPOSER_IMAGE|NODE_IMAGE|PGVECTOR_IMAGE|REDIS_IMAGE|NGINX_IMAGE)=' .env 2>/dev/null || true)"
set +a

PHP_CLI_IMAGE="${PHP_CLI_IMAGE:-php:8.4.27-cli-bookworm}"
COMPOSER_IMAGE="${COMPOSER_IMAGE:-composer:2}"
NODE_IMAGE="${NODE_IMAGE:-node:22-bookworm-slim}"
PGVECTOR_IMAGE="${PGVECTOR_IMAGE:-pgvector/pgvector:pg16}"
REDIS_IMAGE="${REDIS_IMAGE:-redis:7-alpine}"
NGINX_IMAGE="${NGINX_IMAGE:-nginx:1.27-alpine}"

# 将 docker.io 风格镜像名映射到加速前缀路径。
mirror_ref() {
  local image="$1"
  case "$image" in
    */*)
      echo "${MIRROR}/${image}"
      ;;
    *)
      echo "${MIRROR}/library/${image}"
      ;;
  esac
}

pull_and_tag() {
  local image="$1"
  if docker image inspect "$image" >/dev/null 2>&1; then
    echo "  已有本地镜像: $image"
    return 0
  fi
  if [[ -z "$MIRROR" ]]; then
    echo "  docker pull $image"
    docker pull "$image"
    return
  fi
  local mirrored
  mirrored="$(mirror_ref "$image")"
  echo "  加速拉取: $mirrored -> $image"
  docker pull "$mirrored"
  docker tag "$mirrored" "$image"
}

if [[ -n "$MIRROR" ]]; then
  echo "==> 经镜像加速预拉取基础镜像 (MIRROR=${MIRROR})..."
  pull_and_tag "$PHP_CLI_IMAGE"
  pull_and_tag "$COMPOSER_IMAGE"
  pull_and_tag "$NODE_IMAGE"
  pull_and_tag "$PGVECTOR_IMAGE"
  pull_and_tag "$REDIS_IMAGE"
  pull_and_tag "$NGINX_IMAGE"
fi

# apt 源可通过 DEBIAN_MIRROR 覆盖（默认清华）；阿里云不稳定时换 ustc 等
export DEBIAN_MIRROR="${DEBIAN_MIRROR:-mirrors.tuna.tsinghua.edu.cn}"
echo "==> 构建应用镜像（DEBIAN_MIRROR=${DEBIAN_MIRROR}，使用本地基础镜像，--pull=false）..."
docker compose build --pull=false --build-arg "DEBIAN_MIRROR=${DEBIAN_MIRROR}"

echo "==> 启动服务（含 init 迁移与首次安装）..."
docker compose up -d --remove-orphans

echo "==> 等待 web 就绪..."
for i in $(seq 1 60); do
  code="$(curl -fsS -o /dev/null -w "%{http_code}" "http://127.0.0.1:${APP_PORT:-18080}/" 2>/dev/null || true)"
  if [[ "$code" =~ ^[23] ]]; then
    echo "服务已响应 (HTTP $code)"
    break
  fi
  sleep 5
  if [[ "$i" -eq 60 ]]; then
    echo "警告：端口尚未响应，请用 docker compose ps / logs 排查" >&2
  fi
done

echo
echo "=== 状态 ==="
docker compose ps
echo
echo "前台: http://localhost:18080"
echo "后台: http://localhost:18080/geo_admin/login"
echo "账号: admin"
echo "密码: password"
