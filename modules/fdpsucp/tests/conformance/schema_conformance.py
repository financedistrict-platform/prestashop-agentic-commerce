#!/usr/bin/env python3
"""
UCP schema-conformance test.

Validates the module's LIVE responses against the canonical UCP JSON Schemas in
the spec repo (tools/ucp/source/schemas) — draft 2020-12, relative $refs
resolved through a referencing Registry keyed by each schema's $id.

This is the automated form of Definition-of-Done item 5 ("Discovery + sessions
validate against the UCP schema"). Run from the host (has python + curl access
to the store):

    python tests/conformance/schema_conformance.py
    BASE_URL=http://localhost:8080 SCHEMA_DIR=/path/to/tools/ucp/source/schemas \
        python tests/conformance/schema_conformance.py

Requires: jsonschema (>=4.18, pulls in `referencing`).
"""
import json
import os
import sys
import urllib.request
from pathlib import Path

from jsonschema import Draft202012Validator
from referencing import Registry, Resource
from referencing.jsonschema import DRAFT202012

BASE_URL = os.environ.get("BASE_URL", "http://localhost:8080").rstrip("/")
UCP_API = os.environ.get("UCP_API", f"{BASE_URL}/module/fdpsucp/api").rstrip("/")

# Default: repo_root/tools/ucp/source/schemas  (script is …/fdpsucp/tests/conformance)
DEFAULT_SCHEMAS = Path(__file__).resolve().parents[5] / "tools" / "ucp" / "source" / "schemas"
SCHEMA_DIR = Path(os.environ.get("SCHEMA_DIR", str(DEFAULT_SCHEMAS)))


def build_registry(schema_dir: Path) -> Registry:
    """Load every schema under schema_dir, register it by its $id."""
    resources = []
    for path in schema_dir.rglob("*.json"):
        contents = json.loads(path.read_text(encoding="utf-8"))
        uri = contents.get("$id")
        if not uri:
            continue
        resources.append((uri, Resource.from_contents(contents, default_specification=DRAFT202012)))
    return Registry().with_resources(resources)


def http_json(method: str, url: str, body: dict | None = None) -> tuple[int, dict]:
    data = json.dumps(body).encode() if body is not None else None
    req = urllib.request.Request(url, data=data, method=method,
                                 headers={"Content-Type": "application/json"})
    try:
        with urllib.request.urlopen(req) as resp:
            return resp.status, json.loads(resp.read().decode())
    except urllib.error.HTTPError as e:  # 4xx/5xx still carry a JSON body
        return e.code, json.loads(e.read().decode())


REGISTRY = build_registry(SCHEMA_DIR)
PASS, FAIL = 0, 0


def validate(label: str, schema_ref: str, instance: dict) -> None:
    """Validate `instance` against the schema identified by `schema_ref` ($id[#/pointer])."""
    global PASS, FAIL
    validator = Draft202012Validator({"$ref": schema_ref}, registry=REGISTRY)
    errors = sorted(validator.iter_errors(instance), key=lambda e: list(e.path))
    if not errors:
        print(f"  [ok] {label}")
        PASS += 1
        return
    FAIL += 1
    print(f"  [XX] {label}  ({len(errors)} error(s))")
    for e in errors[:8]:
        loc = "$." + ".".join(str(p) for p in e.path) if e.path else "$"
        print(f"      - {loc}: {e.message}")


def main() -> int:
    print("=" * 44)
    print("  UCP schema conformance")
    print(f"  target : {UCP_API}")
    print(f"  schemas: {SCHEMA_DIR}")
    print("=" * 44)

    S = "https://ucp.dev/schemas"

    # 1. Discovery -> profile.json
    print("\n1. Discovery")
    _, disc = http_json("GET", f"{BASE_URL}/.well-known/ucp")
    validate("/.well-known/ucp conforms to profile.json", f"{S}/profile.json", disc)

    # 2. Catalog search -> catalog_search.json#/$defs/search_response
    # NOTE: catalog prices are intentionally major-units floats (agent budgeting +
    # WC/Shopware parity), so `price_range.*.amount` will report as "not integer"
    # against the spec's amount:integer type. That divergence is expected here.
    print("\n2. Catalog")
    _, search = http_json("POST", f"{UCP_API}/catalog/search", {"limit": 5})
    validate("catalog/search conforms to search_response",
             f"{S}/shopping/catalog_search.json#/$defs/search_response", search)
    product_id = (search.get("products") or [{}])[0].get("id")

    if not product_id:
        print("  ! no product returned — skipping session/order checks")
        return summary()

    # 3. Checkout session -> checkout.json
    print("\n3. Checkout session")
    _, created = http_json("POST", f"{UCP_API}/checkout-sessions",
                           {"line_items": [{"item": {"id": product_id}, "quantity": 1}]})
    sid = created.get("id")
    http_json("PUT", f"{UCP_API}/checkout-sessions/{sid}", {
        "buyer": {"email": "test@example.com", "first_name": "Test", "last_name": "Agent"},
        "fulfillment": {"methods": [{"type": "shipping", "destinations": [{
            "street_address": "1 Market St", "address_locality": "San Francisco",
            "address_region": "CA", "postal_code": "94105", "address_country": "US"}]}]},
    })
    _, session = http_json("GET", f"{UCP_API}/checkout-sessions/{sid}")
    validate("checkout session conforms to checkout.json", f"{S}/shopping/checkout.json", session)

    # 4. Order -> order.json  (complete on the dummy handler first)
    print("\n4. Order")
    _, completed = http_json("POST", f"{UCP_API}/checkout-sessions/{sid}/complete",
                             {"payment": {"instruments": [{"handler_id": "dummy", "credential": {}}]}})
    order_id = (completed.get("order") or {}).get("id")
    if order_id:
        _, order = http_json("GET", f"{UCP_API}/orders/{order_id}")
        validate("order conforms to order.json", f"{S}/shopping/order.json", order)
    else:
        print("  ! complete returned no order id — skipping order check")

    return summary()


def summary() -> int:
    print("\n" + "=" * 44)
    print(f"  PASS={PASS}  FAIL={FAIL}")
    print("=" * 44)
    return 0 if FAIL == 0 else 1


if __name__ == "__main__":
    sys.exit(main())
