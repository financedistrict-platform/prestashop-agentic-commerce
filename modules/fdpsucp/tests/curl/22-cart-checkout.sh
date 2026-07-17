#!/usr/bin/env bash
set -euo pipefail
. "$(dirname "$0")/00-config.sh"

if [ -z "${CART_ID:-}" ]; then
  echo "Usage: CART_ID=<id> $0"
  exit 1
fi

echo "=== Convert Cart $CART_ID to Checkout Session ==="
# Sends the cart's secret (UCP_SECRET) to authorize the cart. The conversion
# mints a NEW session_secret — re-export UCP_SECRET from the value below.
RESPONSE=$(curl -s "${AUTH[@]}" "${SECRET_HEADER[@]}" -X POST "$UCP_API/carts/$CART_ID/checkout" \
  -H "Content-Type: application/json" \
  ${UCP_AGENT:+-H "UCP-Agent: $UCP_AGENT"})

echo "$RESPONSE" | "$PY" -m json.tool

SESSION_ID=$(echo "$RESPONSE" | "$PY" -c "import json,sys; print(json.load(sys.stdin)['id'])" 2>/dev/null || true)
SESSION_SECRET=$(echo "$RESPONSE" | "$PY" -c "import json,sys; print(json.load(sys.stdin)['session_secret'])" 2>/dev/null || true)
if [ -n "$SESSION_ID" ]; then
  echo ""
  echo "Session ID: $SESSION_ID"
  echo "Export it:  export SESSION_ID=$SESSION_ID"
fi
if [ -n "$SESSION_SECRET" ]; then
  echo "New session secret (shown once): $SESSION_SECRET"
  echo "Export it:  export UCP_SECRET=$SESSION_SECRET"
fi
