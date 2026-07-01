<?php
/**
 * FR-15 multistore isolation — integration test.
 *
 * Proves the core guarantee: rows written under one shop id are invisible to
 * queries scoped to a different shop id. Exercises the real SessionRepository /
 * CartRepository against the real database (no HTTP, no shop reconfiguration).
 *
 * The domain -> shop-id resolution itself is PrestaShop-native (context->shop
 * from ps_shop_url matching the request Host); what this test locks down is the
 * module's own `id_shop` scoping, which is where a leak would actually happen.
 *
 * Run inside the container (DB is reachable as `ps-db`):
 *   docker exec -u www-data -w /var/www/html \
 *     fd-prestashop-demo-prestashop-1 \
 *     php modules/fdpsucp/tests/integration/multistore-isolation.php
 */

$psRoot = dirname(__DIR__, 4); // .../tests/integration -> PrestaShop root
require_once $psRoot . '/config/config.inc.php';
require_once dirname(__DIR__, 2) . '/src/autoload.php';

use FD\PrismUcp\Cart\CartRepository;
use FD\PrismUcp\Ucp\SessionRepository;

$pass = 0;
$fail = 0;
function check(string $label, bool $cond): void
{
    global $pass, $fail;
    if ($cond) {
        echo "  \xE2\x9C\x93 $label\n";
        $pass++;
    } else {
        echo "  \xE2\x9C\x97 $label\n";
        $fail++;
    }
}

const SHOP_A = 1;
const SHOP_B = 2; // need not exist as a real shop — a different id is enough to prove scoping

$sessions = new SessionRepository();
$carts = new CartRepository();
$now = date('Y-m-d H:i:s');

$uid = 'iso-sess-' . uniqid();
$idem = 'iso-idem-' . uniqid();
$cuid = 'iso-cart-' . uniqid();

echo "FR-15 multistore isolation (shop A=" . SHOP_A . " vs shop B=" . SHOP_B . ")\n\n";

// ── Session isolation ─────────────────────────────────────────
$sessions->insert([
    'session_uid' => $uid,
    'id_shop' => SHOP_A,
    'status' => 'incomplete',
    'currency' => 'USD',
    'line_items' => json_encode([['id' => 'li_1']]),
    'idempotency_key' => $idem,
    'created_at' => $now,
]);

echo "Session:\n";
check('visible on its own shop (A)', $sessions->findByUid($uid, SHOP_A) !== null);
check('INVISIBLE on another shop (B)', $sessions->findByUid($uid, SHOP_B) === null);
check('idempotency lookup scoped to shop A', $sessions->findByIdempotencyKey($idem, SHOP_A) !== null);
check('idempotency lookup invisible on shop B', $sessions->findByIdempotencyKey($idem, SHOP_B) === null);
// Atomic claim (NFR-2) must also be shop-scoped: another shop cannot claim it.
check('claimForCompletion refused on wrong shop (B)', $sessions->claimForCompletion($uid, SHOP_B) === false);
check('claimForCompletion succeeds on owning shop (A)', $sessions->claimForCompletion($uid, SHOP_A) === true);

// ── Cart isolation ────────────────────────────────────────────
$carts->insert([
    'cart_uid' => $cuid,
    'id_shop' => SHOP_A,
    'line_items' => json_encode([['id' => 'li_1']]),
    'created_at' => $now,
]);

echo "\nCart:\n";
check('visible on its own shop (A)', $carts->findByUid($cuid, SHOP_A) !== null);
check('INVISIBLE on another shop (B)', $carts->findByUid($cuid, SHOP_B) === null);

// ── Cleanup ───────────────────────────────────────────────────
Db::getInstance()->delete('prism_session', '`session_uid` = "' . pSQL($uid) . '"');
Db::getInstance()->delete('prism_cart', '`cart_uid` = "' . pSQL($cuid) . '"');

echo "\n  PASS=$pass  FAIL=$fail\n";
exit($fail === 0 ? 0 : 1);
