#!/usr/bin/env bash
# Shared config for all curl tests. Source it:  . ./00-config.sh
#
#   BASE_URL   store origin                (default http://localhost:8080)
#   UCP_API    shopping-service base        (default $BASE_URL/ucp/v1)
#   UCP_TOKEN  agent token, if the module enforces one (FDPSUCP_AGENT_TOKEN set)
#
# The UCP endpoint is discovered from /.well-known/ucp in real clients; here we
# default it to the /ucp/v1 native route the module advertises.

BASE_URL="${BASE_URL:-http://localhost:8080}"
UCP_API="${UCP_API:-$BASE_URL/module/fdpsucp/api}"

# Optional bearer token (auth is token-OPTIONAL by default).
AUTH=()
if [ -n "${UCP_TOKEN:-}" ]; then
  AUTH=(-H "Authorization: Bearer $UCP_TOKEN")
fi

# Per-resource capability secret. Creating a cart or checkout session returns a
# one-time secret in the response body (cart_secret / session_secret). Export it
# — the create scripts print the exact command — so every later call to that
# resource authorizes. Sent here as UCP-Session-Secret, which the module accepts
# for both carts and sessions (UCP-Cart-Secret is an accepted alias for carts).
SECRET_HEADER=()
if [ -n "${UCP_SECRET:-}" ]; then
  SECRET_HEADER=(-H "UCP-Session-Secret: $UCP_SECRET")
fi

# Pick a working Python (used for JSON parsing). On Windows/Git Bash the bare
# `python3` name is often a Store shim that fails, while `python` works.
if [ -z "${PY:-}" ]; then
  if command -v python3 >/dev/null 2>&1 && python3 -c '1' >/dev/null 2>&1; then
    PY=python3
  elif command -v python >/dev/null 2>&1; then
    PY=python
  else
    echo "ERROR: no working python found (need python3 or python for JSON parsing)" >&2
    return 1 2>/dev/null || exit 1
  fi
fi
