#!/usr/bin/env bash
# 将 MediaCrawler data 目录结果导入本机 GEOFlow Docker。
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
ENV_FILE="${GEOFLOW_BRIDGE_ENV:-$ROOT/.env.competitor-bridge.local}"

if [[ -f "$ENV_FILE" ]]; then
  # shellcheck disable=SC1090
  set -a
  source "$ENV_FILE"
  set +a
fi

: "${GEOFLOW_API_TOKEN:?请设置 GEOFLOW_API_TOKEN，或写入 $ENV_FILE}"

export GEOFLOW_API_BASE="${GEOFLOW_API_BASE:-http://127.0.0.1:18080}"
export GEOFLOW_API_HOST="${GEOFLOW_API_HOST:-ops.guiyunbao.cn}"
export MEDIACRAWLER_DATA_ROOT="${MEDIACRAWLER_DATA_ROOT:-/Users/a1234/Documents/Code/AI/GEO/MediaCrawler/data}"

exec python3 "$ROOT/scripts/mediacrawler_bridge.py" "$@"
