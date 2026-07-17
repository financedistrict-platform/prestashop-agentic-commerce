#!/usr/bin/env bash
set -euo pipefail
. "$(dirname "$0")/00-config.sh"

ORDER_ID="${1:-${ORDER_ID:-}}"
if [ -z "$ORDER_ID" ]; then
  echo "Usage: $0 <order_id>   (or ORDER_ID=<id> $0)"
  exit 1
fi

echo "=== Get Order $ORDER_ID ==="
# The order read requires the originating session's secret (export UCP_SECRET);
# without it the module returns 404 to avoid confirming the order exists.
curl -s "${AUTH[@]}" "${SECRET_HEADER[@]}" "$UCP_API/orders/$ORDER_ID" | "$PY" -m json.tool
