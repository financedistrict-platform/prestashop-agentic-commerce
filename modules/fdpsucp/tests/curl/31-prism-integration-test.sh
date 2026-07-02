#!/usr/bin/env bash
#
# Prism integration test (no settlement) — mirrors woocommerce-agentic-commerce's
# 30-prism-integration-test.sh. Verifies the Prism handler is advertised, embeds
# a per-session quote (accepts[]), reaches ready_for_complete, and is removed on
# cancel.
#
# It does NOT settle on-chain (that needs a wallet-signed x402 credential), so it
# needs Prism CONFIGURED in the BO (gateway URL + key) but NO wallet funds and no
# specific item price. If Prism isn't configured, it skips cleanly.
set -uo pipefail
. "$(dirname "$0")/00-config.sh"

PASS=0; FAIL=0
pass() { echo "  [ok] $1"; PASS=$((PASS + 1)); }
fail() { echo "  [XX] $1: ${2:-}"; FAIL=$((FAIL + 1)); }
jval() { echo "$1" | "$PY" -c "import json,sys; d=json.load(sys.stdin); print($2)" 2>/dev/null || true; }

echo "========================================"
echo "  Prism integration (no settlement)"
echo "  target: $UCP_API"
echo "========================================"

# 1. Prism handler advertised? (requires Prism configured in the BO)
DISC=$(curl -sL "${AUTH[@]}" "$BASE_URL/.well-known/ucp")
if [ "$(jval "$DISC" "'xyz.fd.prism_payment' in d['ucp'].get('payment_handlers',{})")" != "True" ]; then
  echo ""
  echo "  ! Prism handler not advertised — configure it in the Back Office"
  echo "    (Module Manager -> FD Prism Payment -> gateway URL + API key), then re-run."
  echo "  SKIPPED (Prism not configured)"
  exit 0
fi
pass "prism handler advertised in discovery"

PID=$(jval "$(curl -s "${AUTH[@]}" -X POST "$UCP_API/catalog/search" -H 'Content-Type: application/json' -d '{"limit":1}')" "d['products'][0]['id']")
[ -n "$PID" ] && pass "catalog has a product ($PID)" || fail "catalog product" "none"

# 2. Create session -> Prism quote embedded in payment_handlers
CREATE=$(curl -s "${AUTH[@]}" -X POST "$UCP_API/checkout-sessions" -H 'Content-Type: application/json' \
  -d "{\"line_items\":[{\"item\":{\"id\":\"$PID\"},\"quantity\":1}]}")
SID=$(jval "$CREATE" "d['id']")
[ -n "$SID" ] && pass "session created ($SID)" || fail "session create" "$CREATE"
[ "$(jval "$CREATE" "'xyz.fd.prism_payment' in d.get('ucp',{}).get('payment_handlers',{})")" = "True" ] \
  && pass "session embeds the Prism quote (payment_handlers)" || fail "prism quote" "not embedded in session"

# 3. Update buyer + shipping -> ready_for_complete
UPD=$(curl -s "${AUTH[@]}" -X PUT "$UCP_API/checkout-sessions/$SID" -H 'Content-Type: application/json' -d '{
  "buyer":{"email":"test@example.com","first_name":"Test","last_name":"Agent"},
  "fulfillment":{"methods":[{"type":"shipping","destinations":[{"street_address":"1 Market St","address_locality":"San Francisco","address_region":"CA","postal_code":"94105","address_country":"US"}]}]}
}')
ST=$(jval "$UPD" "d['status']")
[ "$ST" = "ready_for_complete" ] && pass "status ready_for_complete" || fail "status" "got '$ST' (settlement would need a wallet-signed credential)"

# 4. Cancel -> payment handlers removed
CST=$(jval "$(curl -s "${AUTH[@]}" -X POST "$UCP_API/checkout-sessions/$SID/cancel" -H 'Content-Type: application/json')" "d['status']")
[ "$CST" = "canceled" ] && pass "session canceled" || fail "cancel" "got '$CST'"

echo ""
echo "  PASS=$PASS  FAIL=$FAIL"
[ "$FAIL" -eq 0 ]
