#!/usr/bin/env bash
set -euo pipefail
. "$(dirname "$0")/00-config.sh"

# Defaults to the dummy handler (always succeeds, no gateway). For a real Prism
# settlement, set HANDLER_ID=x402 and pass a signed x402 credential in CREDENTIAL.
HANDLER_ID="${HANDLER_ID:-dummy}"
CREDENTIAL="${CREDENTIAL:-{\}}"

if [ -z "${SESSION_ID:-}" ]; then
  echo "Usage: SESSION_ID=<id> [HANDLER_ID=dummy] [CREDENTIAL='<json>'] $0"
  exit 1
fi

echo "=== Complete Checkout Session $SESSION_ID (handler: $HANDLER_ID) ==="
curl -s "${AUTH[@]}" "${SECRET_HEADER[@]}" -X POST "$UCP_API/checkout-sessions/$SESSION_ID/complete" \
  -H "Content-Type: application/json" \
  -d "{
    \"payment\": {
      \"instruments\": [{
        \"handler_id\": \"$HANDLER_ID\",
        \"credential\": $CREDENTIAL
      }]
    }
  }" | "$PY" -m json.tool
