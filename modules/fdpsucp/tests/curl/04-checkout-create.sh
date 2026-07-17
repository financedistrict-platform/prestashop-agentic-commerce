#!/usr/bin/env bash
set -euo pipefail
. "$(dirname "$0")/00-config.sh"

PRODUCT_ID="${1:-1}"
QUANTITY="${2:-1}"

echo "=== Create Checkout Session (product $PRODUCT_ID x$QUANTITY) ==="
RESPONSE=$(curl -s "${AUTH[@]}" -X POST "$UCP_API/checkout-sessions" \
  -H "Content-Type: application/json" \
  -d "{\"line_items\": [{\"item\": {\"id\": \"$PRODUCT_ID\"}, \"quantity\": $QUANTITY}]}")

echo "$RESPONSE" | "$PY" -m json.tool

SESSION_ID=$(echo "$RESPONSE" | "$PY" -c "import json,sys; print(json.load(sys.stdin)['id'])" 2>/dev/null || true)
SESSION_SECRET=$(echo "$RESPONSE" | "$PY" -c "import json,sys; print(json.load(sys.stdin)['session_secret'])" 2>/dev/null || true)
if [ -n "$SESSION_ID" ]; then
  echo ""
  echo "Session ID: $SESSION_ID"
  echo "Export it:  export SESSION_ID=$SESSION_ID"
fi
if [ -n "$SESSION_SECRET" ]; then
  # Required on every later call to this session (update/complete) — omitting it
  # returns 403 session_secret_required.
  echo "Session secret (shown once): $SESSION_SECRET"
  echo "Export it:  export UCP_SECRET=$SESSION_SECRET"
fi
