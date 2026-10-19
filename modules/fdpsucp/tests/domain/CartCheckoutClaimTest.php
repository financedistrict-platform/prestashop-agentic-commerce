<?php

declare(strict_types=1);

use FD\PrismUcp\Cart\CartRepository;
use FD\PrismUcp\Cart\CartService;
use FD\PrismUcp\Http\Response;
use FD\PrismUcp\Payment\PaymentRegistry;
use FD\PrismUcp\Ucp\VersionRegistry;
use PHPUnit\Framework\TestCase;

final class CartCheckoutClaimTest extends TestCase
{
    private const CART_ID = '7b1d9c34-2e5f-4a68-8c03-d19e4f6a2b57';
    private const SECRET = 'test';
    private const FINGERPRINT = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';

    private FdTestClaimedCarts $carts;

    protected function setUp(): void
    {
        FdTestStubs::reset();
        VersionRegistry::seedOnUpgrade();

        $session = FdTestGoldenRenderer::input('checkout-session.json');
        $session['ucp_version'] = null;
        Db::$row = $session;

        $this->carts = new FdTestClaimedCarts();
        $this->carts->rows[self::CART_ID] = [
            'cart_uid' => self::CART_ID,
            'id_shop' => 1,
            'line_items' => json_encode([['item' => ['id' => '101'], 'quantity' => 2]]),
            'agent_fingerprint' => self::FINGERPRINT,
            'cart_secret_hash' => hash('sha256', self::SECRET),
            'ucp_version' => null,
            'created_at' => '2026-04-20 10:00:00',
            'updated_at' => '2026-04-20 10:00:00',
        ];

        Product::$prices = [101 => ['EUR' => 18.00]];
        Context::getContext()->currency = new Currency(Currency::getIdByIsoCode('EUR'));
    }

    private function checkout(): Response
    {
        $registry = new PaymentRegistry();
        $registry->register(new FdTestRecordingPrismHandler());
        $service = new CartService(Context::getContext(), $registry, FdTestGoldenRenderer::ENDPOINT, self::FINGERPRINT, self::SECRET);
        (new ReflectionProperty(CartService::class, 'carts'))->setValue($service, $this->carts);

        return $service->checkout(self::CART_ID, null);
    }

    private static function sessionsCreated(): int
    {
        return count(array_filter(Db::$inserted, static fn (array $insert): bool => $insert['table'] === 'prism_session'));
    }

    public function test_lost_claim_creates_no_session(): void
    {
        $this->carts->claimed[self::CART_ID] = true;

        $response = $this->checkout();

        $this->assertSame(409, $response->status, (string) json_encode($response->body));
        $this->assertSame('cart_already_checked_out', $response->body['messages'][0]['code']);
        $this->assertSame(0, self::sessionsCreated());
    }

    public function test_two_checkouts_of_one_cart_create_exactly_one_session(): void
    {
        $first = $this->checkout();
        $second = $this->checkout();

        $this->assertSame(201, $first->status, (string) json_encode($first->body));
        $this->assertSame(409, $second->status, (string) json_encode($second->body));
        $this->assertSame('cart_already_checked_out', $second->body['messages'][0]['code']);
        $this->assertSame(1, self::sessionsCreated());
    }

    public function test_session_create_failure_after_a_won_claim_is_reported(): void
    {
        $this->carts->rows[self::CART_ID]['line_items'] = json_encode([['item' => ['id' => 'abc'], 'quantity' => 1]]);

        $response = $this->checkout();

        $this->assertSame(500, $response->status, (string) json_encode($response->body));
        $this->assertSame('cart_checkout_failed', $response->body['messages'][0]['code']);
        $this->assertSame(0, self::sessionsCreated());
        $this->assertTrue($this->carts->claimed[self::CART_ID] ?? false);
    }

    public function test_claim_deletes_the_cart_row_and_wins_only_when_one_row_was_deleted(): void
    {
        $repository = new CartRepository();

        Db::$affected = 1;
        $won = $repository->claim(self::CART_ID, 1);
        Db::$affected = 0;
        $lost = $repository->claim(self::CART_ID, 1);

        $this->assertTrue($won);
        $this->assertFalse($lost);
        $this->assertStringStartsWith('DELETE FROM `ps_prism_cart`', trim(Db::$statements[0]));
        $this->assertStringContainsString('`cart_uid` = "' . self::CART_ID . '"', Db::$statements[0]);
        $this->assertStringContainsString('`id_shop` = 1', Db::$statements[0]);
    }
}

final class FdTestClaimedCarts extends CartRepository
{
    public array $rows = [];
    public array $claimed = [];

    public function __construct()
    {
    }

    public function findByUid(string $uid, int $idShop): ?array
    {
        return $this->rows[$uid] ?? null;
    }

    public function claim(string $id, int $idShop): bool
    {
        if (!isset($this->rows[$id]) || isset($this->claimed[$id])) {
            return false;
        }
        $this->claimed[$id] = true;

        return true;
    }
}
