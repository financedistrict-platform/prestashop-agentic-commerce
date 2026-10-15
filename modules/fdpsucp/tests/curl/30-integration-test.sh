#!/usr/bin/env bash
# UCP integration test — full agent journey against a live store, on the dummy
# handler (no gateway). Discovers a product from the catalog, then runs
# checkout and cart flows end-to-end with assertions.
#
#   BASE_URL=http://localhost:8080 ./30-integration-test.sh
#
# Requires: curl + python (see 00-config.sh). Uses the fdpsdummy handler, so no
# Prism credentials are needed.
set -uo pipefail
. "$(dirname "$0")/00-config.sh"

PASS=0; FAIL=0; SKIP=0
pass() { echo "  ✓ $1"; PASS=$((PASS + 1)); }
fail() { echo "  ✗ $1: ${2:-}"; FAIL=$((FAIL + 1)); }
skip() { echo "  - $1 (skipped)"; SKIP=$((SKIP + 1)); }

# jval <json> <python-expression on `d`>  -> prints value or empty on error
jval() { echo "$1" | "$PY" -c "import json,sys; d=json.load(sys.stdin); print($2)" 2>/dev/null || true; }
# http_status METHOD URL [data] [secret] -> prints numeric status
http_status() {
  local m="$1" url="$2" data="${3:-}" secret="${4:-}"
  local args=("${AUTH[@]}")
  if [ -n "$secret" ]; then
    args+=(-H "UCP-Session-Secret: $secret")
  fi
  if [ -n "$data" ]; then
    curl -s -o /dev/null -w '%{http_code}' "${args[@]}" -X "$m" "$url" -H 'Content-Type: application/json' -d "$data"
  else
    curl -s -o /dev/null -w '%{http_code}' "${args[@]}" -X "$m" "$url"
  fi
}

HDR=$(mktemp)
trap 'rm -f "$HDR"' EXIT

echo "========================================"
echo "  UCP Integration Test Suite"
echo "  Target: $UCP_API"
echo "========================================"

# ── 1. Discovery ──────────────────────────────────────────────
echo ""; echo "1. Discovery"
DISC=$(curl -sL "${AUTH[@]}" "$BASE_URL/.well-known/ucp")
VER=$(jval "$DISC" "d['ucp']['version']")
[ -n "$VER" ] && pass "discovery returns ucp.version ($VER)" || fail "discovery ucp.version" "empty"
ENDPOINT=$(jval "$DISC" "d['ucp']['services']['dev.ucp.shopping'][0]['endpoint']")
case "$ENDPOINT" in
  */module/fdpsucp/api) pass "endpoint advertises /module/fdpsucp/api ($ENDPOINT)" ;;
  *) fail "endpoint" "expected .../module/fdpsucp/api, got '$ENDPOINT'" ;;
esac
CAPS=$(jval "$DISC" "len(d['ucp']['capabilities'])")
[ "${CAPS:-0}" -ge 6 ] 2>/dev/null && pass "discovery lists >=6 capabilities ($CAPS)" || fail "capabilities" "got ${CAPS:-0}"
HAS_ORDER=$(jval "$DISC" "'dev.ucp.shopping.order' in d['ucp']['capabilities']")
[ "$HAS_ORDER" = "True" ] && pass "order capability is singular (dev.ucp.shopping.order)" || fail "order capability" "not found (plural regression?)"

# ── 2. Catalog ────────────────────────────────────────────────
echo ""; echo "2. Catalog"
SEARCH=$(curl -s "${AUTH[@]}" -X POST "$UCP_API/catalog/search" -H 'Content-Type: application/json' -d '{"limit":5}')
PRODUCT_ID=$(jval "$SEARCH" "d['products'][0]['id']")
[ -n "$PRODUCT_ID" ] && pass "catalog search returns a product (id=$PRODUCT_ID)" || fail "catalog search" "no products"
# Catalog prices must be major-units floats (e.g. 11.55), not minor-unit ints
# (1155). A JSON float parses to a Python float; a minor-unit int parses to int.
PRICE_IS_FLOAT=$(jval "$SEARCH" "isinstance(d['products'][0]['price_range']['min']['amount'], float)")
[ "$PRICE_IS_FLOAT" = "True" ] && pass "catalog price is major-units (float)" \
  || fail "catalog price format" "expected major-units float, got a non-float (minor-unit cents?)"
if [ -n "$PRODUCT_ID" ]; then
  LOOKUP=$(curl -s "${AUTH[@]}" -X POST "$UCP_API/catalog/lookup" -H 'Content-Type: application/json' -d "{\"ids\":[\"$PRODUCT_ID\"]}")
  LID=$(jval "$LOOKUP" "d['products'][0]['id']")
  [ "$LID" = "$PRODUCT_ID" ] && pass "catalog lookup resolves the product" || fail "catalog lookup" "got '$LID'"
else
  skip "catalog lookup"
fi

# ── 3. Checkout journey ───────────────────────────────────────
echo ""; echo "3. Checkout journey (dummy handler)"
if [ -z "$PRODUCT_ID" ]; then
  skip "checkout journey (no product)"
else
  CREATE=$(curl -s -D "$HDR" "${AUTH[@]}" -X POST "$UCP_API/checkout-sessions" -H 'Content-Type: application/json' \
    -d "{\"line_items\":[{\"item\":{\"id\":\"$PRODUCT_ID\"},\"quantity\":1}]}")
  SID=$(jval "$CREATE" "d['id']")
  ST=$(jval "$CREATE" "d['status']")
  SECRET=$(header_secret "$HDR" || true)
  [ -n "$SID" ] && pass "session created (id=$SID)" || fail "session create" "$CREATE"
  [ "$ST" = "incomplete" ] && pass "new session is incomplete" || fail "new session status" "got '$ST'"
  [ -n "$SECRET" ] && pass "session create returns the UCP-Session-Secret header" || fail "session secret header" "missing"
  if [ -n "$SECRET" ]; then
    case "$CREATE" in
      *"$SECRET"*) fail "session secret in body" "the secret must only travel in the header" ;;
      *) pass "session body does not contain the secret" ;;
    esac
  fi
  SH=(-H "UCP-Session-Secret: $SECRET")

  if [ -n "$SID" ]; then
    NOH=$(http_status GET "$UCP_API/checkout-sessions/$SID")
    [ "$NOH" = "401" ] && pass "session GET without the secret -> 401" || fail "session GET without secret" "status $NOH"
    BADH=$(http_status GET "$UCP_API/checkout-sessions/$SID" "" "wrong-secret")
    [ "$BADH" = "404" ] && pass "session GET with a wrong secret -> 404" || fail "session GET with wrong secret" "status $BADH"

    UPD=$(curl -s "${AUTH[@]}" "${SH[@]}" -X PUT "$UCP_API/checkout-sessions/$SID" -H 'Content-Type: application/json' -d '{
      "buyer":{"email":"test@example.com","first_name":"Test","last_name":"Agent"},
      "fulfillment":{"methods":[{"type":"shipping","destinations":[{"street_address":"1 Market St","address_locality":"San Francisco","address_region":"CA","postal_code":"94105","address_country":"US"}]}]}
    }')
    UST=$(jval "$UPD" "d['status']")
    case "$UST" in
      ready_for_complete|incomplete) pass "session updated with buyer+address (status=$UST)" ;;
      *) fail "session update" "unexpected status '$UST'" ;;
    esac

    GST=$(http_status GET "$UCP_API/checkout-sessions/$SID" "" "$SECRET")
    [ "$GST" = "200" ] && pass "session GET 200" || fail "session GET" "status $GST"

    COMP=$(curl -s "${AUTH[@]}" "${SH[@]}" -X POST "$UCP_API/checkout-sessions/$SID/complete" -H 'Content-Type: application/json' \
      -d '{"payment":{"instruments":[{"id":"inst_1","handler_id":"dummy","type":"dummy","credential":{"type":"dummy"}}]}}')
    CST=$(jval "$COMP" "d['status']")
    OID=$(jval "$COMP" "d.get('order',{}).get('id','')")
    [ "$CST" = "completed" ] && pass "complete -> status completed" || fail "complete" "status '$CST': $COMP"
    [ -n "$OID" ] && pass "complete returns an order id ($OID)" || fail "order id" "missing"

    if [ -n "$OID" ]; then
      OSTATUS=$(http_status GET "$UCP_API/orders/$OID")
      [ "$OSTATUS" = "401" ] && pass "order GET without the secret -> 401" || fail "order GET without secret" "status $OSTATUS"
      ORD=$(curl -s "${AUTH[@]}" "${SH[@]}" "$UCP_API/orders/$OID")
      RID=$(jval "$ORD" "d['id']")
      [ "$RID" = "$OID" ] && pass "order GET resolves the placed order" || fail "order GET" "got '$RID'"
    else
      skip "order GET"
    fi
  fi
fi

# ── 4. Cart journey ───────────────────────────────────────────
echo ""; echo "4. Cart journey"
if [ -z "$PRODUCT_ID" ]; then
  skip "cart journey (no product)"
else
  CART=$(curl -s -D "$HDR" "${AUTH[@]}" -X POST "$UCP_API/carts" -H 'Content-Type: application/json' \
    -d "{\"line_items\":[{\"item\":{\"id\":\"$PRODUCT_ID\"},\"quantity\":2}]}")
  CID=$(jval "$CART" "d['id']")
  CART_SECRET=$(header_secret "$HDR" || true)
  [ -n "$CID" ] && pass "cart created (id=$CID)" || fail "cart create" "$CART"
  [ -n "$CART_SECRET" ] && pass "cart create returns the UCP-Session-Secret header" || fail "cart secret header" "missing"
  if [ -n "$CID" ]; then
    CNOH=$(http_status GET "$UCP_API/carts/$CID")
    [ "$CNOH" = "401" ] && pass "cart GET without the secret -> 401" || fail "cart GET without secret" "status $CNOH"
    CGST=$(http_status GET "$UCP_API/carts/$CID" "" "$CART_SECRET")
    [ "$CGST" = "200" ] && pass "cart GET 200" || fail "cart GET" "status $CGST"
    CO=$(curl -s -D "$HDR" "${AUTH[@]}" -H "UCP-Session-Secret: $CART_SECRET" -X POST "$UCP_API/carts/$CID/checkout" -H 'Content-Type: application/json')
    CSID=$(jval "$CO" "d['id']")
    NEW_SECRET=$(header_secret "$HDR" || true)
    [ -n "$CSID" ] && pass "cart -> checkout session ($CSID)" || fail "cart checkout" "$CO"
    if [ -n "$NEW_SECRET" ] && [ "$NEW_SECRET" != "$CART_SECRET" ]; then
      pass "cart checkout issues a new session secret"
    else
      fail "cart checkout secret" "expected a new UCP-Session-Secret header"
    fi
  fi
fi

# ── 5. Error handling ─────────────────────────────────────────
echo ""; echo "5. Error handling"
NF=$(http_status GET "$UCP_API/checkout-sessions/does-not-exist" "" "any-secret")
[ "$NF" = "404" ] && pass "unknown session -> 404" || fail "unknown session" "status $NF"
BAD=$(http_status POST "$UCP_API/checkout-sessions" '{"line_items":[{"item":{"id":"99999999"},"quantity":1}]}')
case "$BAD" in
  400|404|422) pass "invalid product -> $BAD" ;;
  *) fail "invalid product" "unexpected status $BAD" ;;
esac

# ── Summary ───────────────────────────────────────────────────
echo ""; echo "========================================"
echo "  PASS=$PASS  FAIL=$FAIL  SKIP=$SKIP"
echo "========================================"
[ "$FAIL" -eq 0 ]
