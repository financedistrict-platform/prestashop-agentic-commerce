#!/usr/bin/env bash
set -euo pipefail
. "$(dirname "$0")/00-config.sh"

if [ -z "${SESSION_ID:-}" ]; then
  echo "Usage: SESSION_ID=<id> $0"
  exit 1
fi

echo "=== Update Checkout Session $SESSION_ID (buyer + shipping address) ==="
curl -s "${AUTH[@]}" -X PUT "$UCP_API/checkout-sessions/$SESSION_ID" \
  -H "Content-Type: application/json" \
  -d '{
    "buyer": {
      "email": "test@example.com",
      "first_name": "Test",
      "last_name": "Agent"
    },
    "fulfillment": {
      "methods": [{
        "type": "shipping",
        "destinations": [{
          "street_address": "1 Market St",
          "address_locality": "San Francisco",
          "address_region": "CA",
          "postal_code": "94105",
          "address_country": "US"
        }]
      }]
    }
  }' | "$PY" -m json.tool
