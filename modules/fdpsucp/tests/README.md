# fdpsucp tests

Two layers, mirroring the WooCommerce reference (BUILD_PLAN Phase 4):

- **`domain/`** — PHPUnit unit tests for the pure logic (address mapping, status
  resolution, error envelope). No store, DB, or PrestaShop kernel needed.
- **`curl/`** — functional tests that exercise the live HTTP surface end-to-end
  on the `fdpsdummy` handler (no Prism credentials required).

## Domain tests (PHPUnit)

There is no PHP CLI on the host, so run PHPUnit inside the running container
(`fd-prestashop-demo`). PHP 8.5 needs PHPUnit 11+.

```bash
C=fd-prestashop-demo-prestashop-1

# one-time: fetch the phar into the container
docker exec "$C" curl -sL -o /tmp/phpunit.phar https://phar.phpunit.de/phpunit-11.phar

# run (module is mounted at /var/www/html/modules/fdpsucp)
docker exec -w /var/www/html/modules/fdpsucp "$C" php /tmp/phpunit.phar --testdox
```

Or, with Composer available: `composer install && vendor/bin/phpunit`.

## Curl functional tests

Run from the host (needs `curl` + `python`/`python3`). Targets the store's
`/module/fdpsucp/api` endpoint; override `BASE_URL` for other stores.

```bash
cd modules/fdpsucp/tests/curl

# full journey with pass/fail summary (discovery -> catalog -> checkout ->
# complete -> order, plus cart flow and error cases)
BASE_URL=http://localhost:8080 bash 30-integration-test.sh

# individual steps (each prints the JSON response)
bash 01-discovery.sh
bash 02-catalog-search.sh
bash 04-checkout-create.sh 19            # product id 19
SESSION_ID=<id> bash 05-checkout-update.sh
SESSION_ID=<id> bash 07-checkout-complete.sh   # HANDLER_ID=dummy, credential {}
bash 09-order-get.sh <order_id>
bash 19-cart-create.sh 19
CART_ID=<id> bash 22-cart-checkout.sh
```

If the module's agent token is enforced (`FDPSUCP_AGENT_TOKEN` set in
`ps_configuration`), export `UCP_TOKEN=<token>` and the scripts will send it as
a bearer token.

## Multistore isolation (FR-15)

Repository-level integration test — proves a session/cart written under one shop
id is invisible to another shop. Runs against the real DB (no HTTP, no shop
reconfiguration):

```bash
docker exec -u www-data -w /var/www/html fd-prestashop-demo-prestashop-1 \
  php modules/fdpsucp/tests/integration/multistore-isolation.php
```

## Schema conformance (validates against the UCP spec repo)

Validates live responses against the canonical JSON Schemas in
`tools/ucp/source/schemas` (draft 2020-12). This is the automated form of
Definition-of-Done item 5. Runs from the host:

```bash
# one-time: a draft-2020-12 validator
python -m pip install jsonschema     # (python -m ensurepip --upgrade first if pip is missing)

BASE_URL=http://localhost:8080 python tests/conformance/schema_conformance.py
```

Checks: `/.well-known/ucp` → `profile.json`, catalog search → `catalog_search.json#/$defs/search_response`,
checkout session → `checkout.json`, order → `order.json`. Override the schema
location with `SCHEMA_DIR=…` if the spec repo lives elsewhere.

## Coverage / scope

- Capabilities the module implements: catalog (search/lookup), cart, checkout,
  fulfillment, order. Returns / promotions / buyer-consent are **not** built, so
  the WooCommerce scripts 13–18 were intentionally not ported.
- Real Prism testnet settlement (Phase 4.3, on-chain) is out of scope for this
  suite — it needs a funded agent wallet and is driven separately.
