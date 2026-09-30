#!/usr/bin/env bash
set -euo pipefail
. "$(dirname "$0")/00-config.sh"

HANDLER_ID="${HANDLER_ID:-xyz.fd.prism_payment}"
INSTRUMENT_TYPE="${INSTRUMENT_TYPE:-x402}"
CREDENTIAL="${CREDENTIAL:-{\"type\":\"x402\"\}}"

if [ -z "${SESSION_ID:-}" ]; then
  echo "Usage: SESSION_ID=<id> [HANDLER_ID=xyz.fd.prism_payment] [INSTRUMENT_TYPE=x402] [CREDENTIAL='<json>'] $0"
  exit 1
fi

echo "=== Complete Checkout Session $SESSION_ID (handler: $HANDLER_ID) ==="
curl -s "${AUTH[@]}" "${SECRET_HEADER[@]}" -X POST "$UCP_API/checkout-sessions/$SESSION_ID/complete" \
  -H "Content-Type: application/json" \
  -d "{
    \"payment\": {
      \"instruments\": [{
        \"id\": \"inst_1\",
        \"handler_id\": \"$HANDLER_ID\",
        \"type\": \"$INSTRUMENT_TYPE\",
        \"credential\": $CREDENTIAL
      }]
    }
  }" | "$PY" -m json.tool
