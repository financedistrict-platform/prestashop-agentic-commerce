<p align="center">
  <h1 align="center">PrestaShop Agentic Commerce</h1>
  <p align="center">
    Make any PrestaShop store discoverable and purchasable by AI agents.
    <br />
    <a href="https://ucp.dev"><strong>UCP Protocol Spec</strong></a> &middot;
    <a href="https://developers.fd.xyz"><strong>Prism Docs</strong></a> &middot;
    <a href="../fd-prestashop-demo"><strong>Demo Store</strong></a>
  </p>
</p>

<p align="center">
  <a href="LICENSE"><img src="https://img.shields.io/badge/license-MIT-blue.svg" alt="License: MIT"></a>
  <a href="#"><img src="https://img.shields.io/badge/PHP-8.1+-8892BF.svg" alt="PHP 8.1+"></a>
  <a href="#"><img src="https://img.shields.io/badge/PrestaShop-1.7.8%20|%208.x%20|%209.x-DF0067.svg" alt="PrestaShop 1.7.8+ / 8.x / 9.x"></a>
  <a href="https://ucp.dev"><img src="https://img.shields.io/badge/UCP-v2026--04--08-green.svg" alt="UCP v2026-04-08"></a>
</p>

---

A PrestaShop module ecosystem that implements the [Universal Commerce Protocol (UCP)](https://ucp.dev). AI agents discover your store via `/.well-known/ucp`, browse your catalog, and complete purchases — with on-chain stablecoin settlement through [Prism](https://developers.fd.xyz) and the [x402 protocol](https://www.x402.org/).

```
AI Agent                        Your PrestaShop Store                   Prism Gateway
   |                                    |                                    |
   |  GET /.well-known/ucp              |                                    |
   |───────────────────────────────────>|                                    |
   |  <- capabilities, payment config   |                                    |
   |                                    |                                    |
   |  POST /module/fdpsucp/api/catalog/search       |                                    |
   |───────────────────────────────────>|                                    |
   |  <- products                       |                                    |
   |                                    |                                    |
   |  POST /module/fdpsucp/api/checkout-sessions    |  POST /payment-requirements        |
   |───────────────────────────────────>|───────────────────────────────────>|
   |  <- session + payment accepts[]    |  <- network, asset, amount, payTo  |
   |                                    |                                    |
   |  POST .../complete (ERC-3009 sig)  |  POST /payment/settle              |
   |───────────────────────────────────>|───────────────────────────────────>|
   |  <- completed order                |  <- tx_hash (on-chain)             |
```

## Table of Contents

- [Quick Start](#quick-start)
- [Modules](#modules)
- [Installation](#installation)
- [Configuration](#configuration)
- [API Reference](#api-reference)
- [Custom Payment Handlers](#custom-payment-handlers)
- [Testing](#testing)
- [Development](#development)
- [License](#license)

## Quick Start

The fastest way to try it out is the [demo store](../fd-prestashop-demo) — a Docker setup (official PrestaShop image + MariaDB) that mounts these modules and ships with sample products.

```bash
git clone https://github.com/financedistrict-platform/fd-prestashop-demo.git
git clone https://github.com/financedistrict-platform/prestashop-agentic-commerce.git
# clone both side-by-side; the demo mounts ../prestashop-agentic-commerce/modules/*
cd fd-prestashop-demo
docker compose up -d
```

Once running, verify your store is agent-discoverable:

```bash
curl http://localhost:8080/.well-known/ucp | jq .
```

## Modules

Three modules. Core works standalone; payment handlers are optional modules that register via a hook.

| Module | Description |
|--------|-------------|
| [`modules/fdpsucp`](modules/fdpsucp) | UCP protocol layer — discovery, catalog, cart, checkout sessions, orders. Serves the shopping service at `/module/fdpsucp/api` (via a web-server rewrite) and defines the payment-handler interface any provider can implement. |
| [`modules/fdpsprism`](modules/fdpsprism) | [Prism](https://developers.fd.xyz) payment handler — on-chain stablecoin settlement (x402 / ERC-3009). Per-shop gateway URL + API key in its own BO settings screen. |
| [`modules/fdpsdummy`](modules/fdpsdummy) | Test handler that always succeeds. Useful for developing against the checkout flow without a wallet or testnet funds. |

## Installation

### Requirements

- PrestaShop 1.7.8+ / 8.x / 9.x
- PHP 8.1+
- A web-server rewrite for `/.well-known/ucp` and `/module/fdpsucp/api/*` (see [`fd-prestashop-demo/apache-fd-ucp.conf`](../fd-prestashop-demo/apache-fd-ucp.conf)). Both work with **Friendly URLs off** — recommended on the official `prestashop:9.1` image, where Friendly URLs on make the Back Office generate admin links without `index.php` that 404. (A clean `/ucp/v1` route via `hookModuleRoutes` is available if you keep Friendly URLs on.)
- A [Prism](https://developers.fd.xyz) account (for real payments)

### As PrestaShop modules

Copy or symlink each module into your `modules/` directory, then install:

```bash
modules/fdpsucp     # UCP core — install first
modules/fdpsprism   # Prism payment handler
modules/fdpsdummy   # optional test handler

php bin/console prestashop:module install fdpsucp
php bin/console prestashop:module install fdpsprism
php bin/console prestashop:module install fdpsdummy
```

Install **fdpsucp** first, then the payment handlers — they register with the core via the `actionUcpCollectPaymentHandlers` hook. Run `bin/console` as the web-server user (e.g. `www-data`), not root, or the cache write will break the Back Office.

The one path that needs a web-server rewrite is the spec-fixed `/.well-known/ucp`; see [`fd-prestashop-demo/apache-fd-ucp.conf`](../fd-prestashop-demo/apache-fd-ucp.conf).

### With Docker

See the [demo store](../fd-prestashop-demo) for a complete Docker Compose setup.

## Configuration

### Prism Payment Handler

1. Go to **Modules → Module Manager → Finance District Prism → Configure**
2. Enter your **Prism Gateway URL** and **API Key** (stored per-shop)
3. Save — the handler is advertised in discovery only once configured

### Agent authentication (optional)

Set `FDPSUCP_AGENT_TOKEN` in `ps_configuration` to require agents to send a bearer token (`Authorization: Bearer <token>` or the `UCP-Agent-Token` header). When unset, the endpoints are open.

### Prism Console Setup

1. Log in to the [Prism Console](https://apps.fd.xyz)
2. Set a **receiving wallet address** for each network you want to accept payments on
3. Ensure the address is not the zero address — settlement will fail otherwise

## API Reference

All shopping endpoints are served under `/module/fdpsucp/api` (advertised as the `endpoint` in discovery).

### Discovery

| Method | Endpoint | Description |
|--------|----------|-------------|
| `GET` | `/.well-known/ucp` | UCP discovery profile — capabilities, payment handlers, store metadata |

### Catalog

| Method | Endpoint | Description |
|--------|----------|-------------|
| `POST` | `/module/fdpsucp/api/catalog/search` | Product search |
| `POST` | `/module/fdpsucp/api/catalog/lookup` | Product lookup by ID |

### Cart

| Method | Endpoint | Description |
|--------|----------|-------------|
| `POST` | `/module/fdpsucp/api/carts` | Create cart |
| `GET` | `/module/fdpsucp/api/carts/{id}` | Get cart |
| `PUT` | `/module/fdpsucp/api/carts/{id}` | Update cart |
| `DELETE` | `/module/fdpsucp/api/carts/{id}` | Delete cart |
| `POST` | `/module/fdpsucp/api/carts/{id}/checkout` | Convert cart to checkout session |

### Checkout

| Method | Endpoint | Description |
|--------|----------|-------------|
| `POST` | `/module/fdpsucp/api/checkout-sessions` | Create checkout session |
| `GET` | `/module/fdpsucp/api/checkout-sessions/{id}` | Get session status |
| `PUT` | `/module/fdpsucp/api/checkout-sessions/{id}` | Update session (buyer, fulfillment, items) |
| `POST` | `/module/fdpsucp/api/checkout-sessions/{id}/complete` | Complete with payment credential |
| `POST` | `/module/fdpsucp/api/checkout-sessions/{id}/cancel` | Cancel session |

### Orders

| Method | Endpoint | Description |
|--------|----------|-------------|
| `GET` | `/module/fdpsucp/api/orders/{id}` | Get order details |

### UCP Capabilities

The discovery endpoint advertises these capabilities per the [UCP spec](https://ucp.dev):

| Capability | Description |
|------------|-------------|
| `dev.ucp.shopping.catalog.search` | Product search |
| `dev.ucp.shopping.catalog.lookup` | Product lookup by ID |
| `dev.ucp.shopping.cart` | Cart CRUD |
| `dev.ucp.shopping.checkout` | Checkout session lifecycle |
| `dev.ucp.shopping.fulfillment` | Shipping methods and addresses |
| `dev.ucp.shopping.order` | Order retrieval |

> Extensions not yet implemented: `dev.ucp.shopping.discount` (promotions), `dev.ucp.shopping.buyer_consent`, returns.

## Custom Payment Handlers

The core module defines a payment-handler interface. Any provider can integrate without modifying core — register via the action hook:

```php
public function hookActionUcpCollectPaymentHandlers(array $params): void
{
    /** @var \FD\PrismUcp\Payment\PaymentRegistry $registry */
    $registry = $params['registry'];
    $registry->register(new MyPaymentHandler());
}
```

Your handler implements `FD\PrismUcp\Payment\PaymentHandlerInterface`:

```php
interface PaymentHandlerInterface
{
    public function id(): string;                                       // e.g. "x402"
    public function name(): string;                                     // e.g. "Prism (x402 Stablecoin)"
    public function getUcpDiscoveryHandlers(): array;                   // advertised in /.well-known/ucp
    public function prepareCheckoutPayment(array $input): ?array;       // called when a session is created
    public function settlePayment(array $input): array;                 // called on complete with the credential
    public function getUcpCheckoutHandlers(?array $paymentMeta = null): array; // shapes the checkout response
}
```

See [`modules/fdpsdummy`](modules/fdpsdummy) for a minimal working example.

## Testing

See [`modules/fdpsucp/tests/README.md`](modules/fdpsucp/tests/README.md) for full run commands.

### Unit tests (PHPUnit)

No PHP CLI is required on the host — run inside the demo container (PHP 8.5 needs PHPUnit 11):

```bash
C=fd-prestashop-demo-prestashop-1
docker exec "$C" curl -sL -o /tmp/phpunit.phar https://phar.phpunit.de/phpunit-11.phar
docker exec -w /var/www/html/modules/fdpsucp   "$C" php /tmp/phpunit.phar --testdox
docker exec -w /var/www/html/modules/fdpsprism "$C" php /tmp/phpunit.phar --testdox
```

### Integration tests

Run against a live store (start the demo first):

```bash
# UCP protocol journey on the dummy handler (curl)
BASE_URL=http://localhost:8080 bash modules/fdpsucp/tests/curl/30-integration-test.sh

# Multistore isolation (FR-15)
docker exec -u www-data -w /var/www/html fd-prestashop-demo-prestashop-1 \
  php modules/fdpsucp/tests/integration/multistore-isolation.php

# Schema conformance vs the UCP spec repo (tools/ucp/source/schemas)
python modules/fdpsucp/tests/conformance/schema_conformance.py
```

### Test Summary

| Module | Unit | Integration | Total |
|--------|------|-------------|-------|
| fdpsucp (UCP core) | 18 | 18 (curl) + 8 (isolation) | 44 |
| fdpsprism (Prism guard) | 14 | — | 14 |
| **Total** | **32** | **34** | **66** |

Schema conformance: discovery and checkout responses conform; catalog and order response shapes have known gaps tracked in `BUILD_PLAN.md` §DoD-5.

## Development

### Project Structure

```
modules/
├── fdpsucp/               # UCP protocol layer
│   ├── src/
│   │   ├── Ucp/           # Formatter, status, error, address, session repo
│   │   ├── Catalog/ Cart/ Checkout/ Orders/   # commerce services
│   │   ├── Payment/       # handler interface + registry
│   │   ├── Http/ Support/ Router.php
│   │   └── ...
│   ├── controllers/front/ # discovery + api (thin JSON HTTP)
│   └── tests/             # domain (phpunit) · curl · integration · conformance
│
├── fdpsprism/             # Prism x402 payment handler
│   ├── src/Prism/         # client, handler, validator (binding guard)
│   ├── src/Config/        # per-shop gateway URL + key resolver
│   └── tests/
│
└── fdpsdummy/             # always-succeeds test handler
```

### Key Design Decisions

- **Own session table** — checkout state lives in a dedicated `ps_prism_session` table (canonical); a PrestaShop `Cart` is used transiently for pricing/shipping only.
- **Handler fan-out** — multiple payment handlers coexist. The registry calls `prepareCheckoutPayment` on all handlers at session creation; the agent selects which to pay with at complete time.
- **Binding guard before settle** — the signed x402 credential is validated (network / asset / recipient / amount, BigInt-safe) against the stored quote *before* any settlement call (NFR-1).
- **Multistore** — the shop is resolved from the request domain (PrestaShop native dispatch); every session/cart query is scoped by `id_shop` (FR-15).
- **Plain merchant labels** — no x402/ERC-3009 jargon in the Back Office; it stays in code and developer docs.

### Staging Environment

| Resource | URL |
|----------|-----|
| Prism Gateway | `https://prism-gw.test.1stdigital.tech` |
| Prism Console | [apps.test.1stdigital.tech](https://apps.test.1stdigital.tech) |
| Network | Base Sepolia (`eip155:84532`) |
| USDC Contract | `0x036cbd53842c5426634e7929541ec2318f3dcf7e` |
| Testnet USDC | [Circle faucet](https://faucet.circle.com/) (Base Sepolia) |

## License

[MIT](LICENSE)
