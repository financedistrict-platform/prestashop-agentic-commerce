#!/usr/bin/env bash
set -euo pipefail
. "$(dirname "$0")/00-config.sh"

ORDER_ID="${1:-${ORDER_ID:-}}"
if [ -z "$ORDER_ID" ]; then
  echo "Usage: $0 <order_id>   (or ORDER_ID=<id> $0)"
  exit 1
fi

echo "=== Get Order $ORDER_ID ==="
curl -s "${AUTH[@]}" "$UCP_API/orders/$ORDER_ID" | "$PY" -m json.tool
