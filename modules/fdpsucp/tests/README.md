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
# complete -> order, plus cart flow and error cases). Completing an order needs
# a payment handler installed (dummy for wallet-free testing).
BASE_URL=http://localhost:8080 bash 30-integration-test.sh

# Prism handler check (no settlement): advertised -> quote embedded ->
# ready_for_complete -> cancel. Needs Prism configured in the BO, no wallet/funds.
# Skips cleanly if Prism isn't configured. Mirrors woo's 30-prism-integration-test.
BASE_URL=http://localhost:8080 bash 31-prism-integration-test.sh

# individual steps (each prints the JSON response)
bash 01-discovery.sh
bash 02-catalog-search.sh
bash 04-checkout-create.sh 19            # product id 19
SESSION_ID=<id> bash 05-checkout-update.sh
SESSION_ID=<id> HANDLER_ID=dummy INSTRUMENT_TYPE=dummy CREDENTIAL='{"type":"dummy"}' bash 07-checkout-complete.sh
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

Validates responses against the JSON Schemas of each UCP spec tag (draft
2020-12). Clone the tags once, outside the repo:

```bash
for t in v2026-01-23 v2026-04-08 v2026-08-25; do
  [ -d "$UCP_SPEC/$t" ] || git clone --depth 1 --branch $t https://github.com/Universal-Commerce-Protocol/ucp.git "$UCP_SPEC/$t"
done
```

Offline mode validates every fixture in a folder. The file name before `__`
picks the `schema-map.json` entry (`ref` = schema `$id` plus optional JSON
pointer, `instance_pointer` = part of the fixture, `each` = validate every
array element). `known_deviations` lists the exact (path, keyword) misses the
2026-04-08 fixtures keep to stay byte-equal with module 0.5.3; a new miss or a
vanished one fails the run.

```bash
V=2026-04-08
uv run --with jsonschema --with referencing python tests/conformance/schema_conformance.py \
  --ucp-version $V --schema-dir "$UCP_SPEC/v$V/source/schemas" \
  --schema-map tests/conformance/schema-map.json --fixtures tests/fixtures/ucp/$V
```

Live mode (no `--fixtures`) checks a running store: `--base-url` / `--ucp-api`
(or `BASE_URL` / `UCP_API`). The last line is `conformance: N failures`.

Golden fixtures: `tests/fixtures/ucp/2026-04-08*` come from running the 0.5.3
release code (`tests/golden/capture-original.php`); `2026-08-25` and
`2026-01-23` are snapshots, rewritten with `FD_UPDATE_SNAPSHOTS=1`.

## Coverage / scope

- Capabilities the module implements: catalog (search/lookup), cart, checkout,
  fulfillment, order. Returns / promotions / buyer-consent are **not** built, so
  the WooCommerce scripts 13–18 were intentionally not ported.
- Real Prism testnet settlement (Phase 4.3, on-chain) is out of scope for this
  suite — it needs a funded agent wallet and is driven separately.
