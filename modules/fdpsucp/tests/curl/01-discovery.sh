#!/usr/bin/env bash
set -euo pipefail
. "$(dirname "$0")/00-config.sh"

echo "=== UCP Discovery ==="
curl -sL "${AUTH[@]}" "$BASE_URL/.well-known/ucp" | "$PY" -m json.tool
