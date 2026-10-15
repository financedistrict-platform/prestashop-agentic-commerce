#!/usr/bin/env bash
set -euo pipefail
. "$(dirname "$0")/00-config.sh"

PRODUCT_ID="${1:-1}"
QUANTITY="${2:-1}"

echo "=== Create Checkout Session (product $PRODUCT_ID x$QUANTITY) ==="
HDR=$(mktemp)
trap 'rm -f "$HDR"' EXIT
RESPONSE=$(curl -s -D "$HDR" "${AUTH[@]}" -X POST "$UCP_API/checkout-sessions" \
  -H "Content-Type: application/json" \
  -d "{\"line_items\": [{\"item\": {\"id\": \"$PRODUCT_ID\"}, \"quantity\": $QUANTITY}]}")

echo "$RESPONSE" | "$PY" -m json.tool

SESSION_ID=$(echo "$RESPONSE" | "$PY" -c "import json,sys; print(json.load(sys.stdin)['id'])" 2>/dev/null || true)
SESSION_SECRET=$(header_secret "$HDR" || true)
if [ -n "$SESSION_ID" ]; then
  echo ""
  echo "Session ID: $SESSION_ID"
  echo "Export it:  export SESSION_ID=$SESSION_ID"
fi
if [ -n "$SESSION_SECRET" ]; then
  echo "Session secret (shown once): $SESSION_SECRET"
  echo "Export it:  export UCP_SECRET=$SESSION_SECRET"
fi
