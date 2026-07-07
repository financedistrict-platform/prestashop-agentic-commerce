#!/usr/bin/env bash
set -euo pipefail
. "$(dirname "$0")/00-config.sh"

if [ -z "${CART_ID:-}" ]; then
  echo "Usage: CART_ID=<id> $0"
  exit 1
fi

echo "=== Convert Cart $CART_ID to Checkout Session ==="
RESPONSE=$(curl -s "${AUTH[@]}" -X POST "$UCP_API/carts/$CART_ID/checkout" \
  -H "Content-Type: application/json" \
  ${UCP_AGENT:+-H "UCP-Agent: $UCP_AGENT"})

echo "$RESPONSE" | "$PY" -m json.tool

SESSION_ID=$(echo "$RESPONSE" | "$PY" -c "import json,sys; print(json.load(sys.stdin)['id'])" 2>/dev/null || true)
if [ -n "$SESSION_ID" ]; then
  echo ""
  echo "Session ID: $SESSION_ID"
  echo "Export it:  export SESSION_ID=$SESSION_ID"
fi
