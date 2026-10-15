<?php

declare(strict_types=1);

use FD\PrismUcp\Cart\CartRepository;
use FD\PrismUcp\Cart\CartService;
use FD\PrismUcp\Checkout\CartBuilder;
use FD\PrismUcp\Checkout\CheckoutService;
use FD\PrismUcp\Http\Response;
use FD\PrismUcp\Orders\OrderService;
use FD\PrismUcp\Payment\PaymentRegistry;
use FD\PrismUcp\Ucp\VersionPin;
use FD\PrismUcp\Ucp\VersionRegistry;
use FD\PrismUcp\Ucp\VersionResolver;
use PHPUnit\Framework\TestCase;

final class SessionOwnershipTest extends TestCase
{
    private const SESSION_ID = '5f0c2a8e-3b1d-4c6e-9a7f-2d4b8e1c0a91';
    private const CART_ID = '7b1d9c34-2e5f-4a68-8c03-d19e4f6a2b57';
    private const SECRET = 'test';
    private const FINGERPRINT = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';
    private const HEADER = 'UCP-Session-Secret';
    private const SECRET_SHAPE = '/^[A-Za-z0-9_-]{43}$/';
    private const LINES = [['item' => ['id' => '101'], 'quantity' => 2]];

    private FdTestMemorySessions $sessions;
    private FdTestOwnedCarts $carts;
    private FdTestRecordingPrismHandler $handler;

    protected function setUp(): void
    {
        FdTestStubs::reset();
        VersionRegistry::seedOnUpgrade();

        $session = FdTestGoldenRenderer::input('checkout-session.json');
        $session['ucp_version'] = null;
        $session['session_secret_hash'] = hash('sha256', self::SECRET);
        $session['expires_at'] = date('Y-m-d H:i:s', time() + 600);
        $this->sessions = new FdTestMemorySessions([self::SESSION_ID => $session]);

        $this->carts = new FdTestOwnedCarts();
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

        $this->handler = new FdTestRecordingPrismHandler();
        Product::$prices = [101 => ['EUR' => 18.00]];
        Context::getContext()->currency = new Currency(Currency::getIdByIsoCode('EUR'));
    }

    private function registry(): PaymentRegistry
    {
        $registry = new PaymentRegistry();
        $registry->register($this->handler);

        return $registry;
    }

    private function checkout(string $secret, ?VersionPin $pin = null, string $fingerprint = self::FINGERPRINT): CheckoutService
    {
        $service = new CheckoutService(Context::getContext(), $this->registry(), FdTestGoldenRenderer::ENDPOINT, $fingerprint, $secret, $pin);
        (new ReflectionProperty(CheckoutService::class, 'sessions'))->setValue($service, $this->sessions);
        (new ReflectionProperty(CheckoutService::class, 'cartBuilder'))->setValue($service, new CartBuilder());

        return $service;
    }

    private function cart(string $secret, ?VersionPin $pin = null): CartService
    {
        $service = new CartService(Context::getContext(), $this->registry(), FdTestGoldenRenderer::ENDPOINT, self::FINGERPRINT, $secret, $pin);
        (new ReflectionProperty(CartService::class, 'carts'))->setValue($service, $this->carts);

        return $service;
    }

    private static function code(Response $response): string
    {
        return (string) ($response->body['messages'][0]['code'] ?? '');
    }

    private function rejectingPin(): VersionPin
    {
        return new VersionPin(
            new VersionResolver(FdTestLegacyVersionStore::registry(), new FdTestFixtureProfileFetcher(['https://agent.example/p' => FdTestFixtureProfileFetcher::declaring('2026-04-08')])),
            'profile="https://agent.example/p"'
        );
    }

    private static function argumentsAfterUid(ReflectionMethod $method): array
    {
        $arguments = [];
        foreach (array_slice($method->getParameters(), 1) as $parameter) {
            $type = $parameter->getType();
            $arguments[] = match (true) {
                $type instanceof ReflectionNamedType && $type->getName() === 'array' => [],
                $type !== null && $type->allowsNull() => null,
                default => '',
            };
        }

        return $arguments;
    }

    private function ownedRoutes(): array
    {
        $routes = [];
        $targets = [
            [CheckoutService::class, self::SESSION_ID, 'checkout_not_found', fn (string $secret) => $this->checkout($secret), 'sessions', 'session_secret_hash'],
            [CartService::class, self::CART_ID, 'cart_not_found', fn (string $secret) => $this->cart($secret), 'carts', 'cart_secret_hash'],
        ];
        foreach ($targets as [$class, $uid, $notFound, $factory, $store, $hashColumn]) {
            foreach ((new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                $parameters = $method->getParameters();
                if ($parameters === [] || $parameters[0]->getName() !== 'uid') {
                    continue;
                }
                $routes[$class . '::' . $method->getName()] = [$method, $uid, $notFound, $factory, $store, $hashColumn];
            }
        }

        return $routes;
    }

    private function call(ReflectionMethod $method, object $service, string $uid): Response
    {
        return $method->invokeArgs($service, [$uid, ...self::argumentsAfterUid($method)]);
    }

    private function snapshot(): array
    {
        return [$this->sessions->rows, $this->carts->rows, Db::$inserted];
    }

    public function test_every_owned_route_is_scanned(): void
    {
        $routes = [];
        foreach ([CheckoutService::class, CartService::class] as $class) {
            foreach ((new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                if (!$method->isStatic() && !$method->isConstructor() && $method->getName() !== 'create') {
                    $routes[] = $class . '::' . $method->getName();
                }
            }
        }

        $this->assertCount(8, $routes);
        $this->assertEqualsCanonicalizing($routes, array_keys($this->ownedRoutes()));
    }

    public function test_every_owned_route_without_the_secret_answers_401(): void
    {
        foreach ($this->ownedRoutes() as $name => [$method, $uid, , $factory]) {
            $before = $this->snapshot();

            $response = $this->call($method, $factory(''), $uid);

            $this->assertSame(401, $response->status, $name);
            $this->assertSame('session_secret_required', self::code($response), $name);
            $this->assertSame($before, $this->snapshot(), $name);
        }
    }

    public function test_every_owned_route_with_a_wrong_secret_answers_404(): void
    {
        foreach ($this->ownedRoutes() as $name => [$method, $uid, $notFound, $factory]) {
            $before = $this->snapshot();

            $response = $this->call($method, $factory('wrong'), $uid);

            $this->assertSame(404, $response->status, $name);
            $this->assertSame($notFound, self::code($response), $name);
            $this->assertSame($before, $this->snapshot(), $name);
        }
    }

    public function test_every_owned_route_for_an_unknown_id_answers_like_a_wrong_secret(): void
    {
        foreach ($this->ownedRoutes() as $name => [$method, , $notFound, $factory]) {
            $response = $this->call($method, $factory(self::SECRET), '00000000-0000-4000-8000-000000000000');

            $this->assertSame(404, $response->status, $name);
            $this->assertSame($notFound, self::code($response), $name);
        }
    }

    public static function unusableStoredHashes(): array
    {
        return ['empty' => [''], 'null' => [null], 'malformed' => ['garbage'], 'uppercase' => [strtoupper(hash('sha256', self::SECRET))]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unusableStoredHashes')]
    public function test_every_owned_route_rejects_a_row_without_a_usable_stored_hash(?string $stored): void
    {
        foreach ($this->ownedRoutes() as $name => [$method, $uid, $notFound, $factory, $store, $hashColumn]) {
            $this->{$store}->rows[$uid][$hashColumn] = $stored;
            $before = $this->snapshot();

            $response = $this->call($method, $factory(self::SECRET), $uid);

            $this->assertSame(404, $response->status, $name);
            $this->assertSame($notFound, self::code($response), $name);
            $this->assertSame($before, $this->snapshot(), $name);
        }
    }

    public function test_ownership_is_decided_before_the_version_pin(): void
    {
        $this->sessions->rows[self::SESSION_ID]['ucp_version'] = '2026-08-25';
        $this->carts->rows[self::CART_ID]['ucp_version'] = '2026-08-25';

        $session = $this->checkout('wrong', $this->rejectingPin())->get(self::SESSION_ID);
        $cart = $this->cart('wrong', $this->rejectingPin())->get(self::CART_ID);

        $this->assertSame(404, $session->status);
        $this->assertSame('checkout_not_found', self::code($session));
        $this->assertSame(404, $cart->status);
        $this->assertSame('cart_not_found', self::code($cart));
    }

    public function test_the_version_pin_still_applies_to_the_owner(): void
    {
        $this->sessions->rows[self::SESSION_ID]['ucp_version'] = '2026-08-25';
        $this->carts->rows[self::CART_ID]['ucp_version'] = '2026-08-25';

        $session = $this->checkout(self::SECRET, $this->rejectingPin())->get(self::SESSION_ID);
        $cart = $this->cart(self::SECRET, $this->rejectingPin())->get(self::CART_ID);

        $this->assertSame(422, $session->status);
        $this->assertSame(422, $cart->status);
    }

    public function test_session_create_returns_the_secret_in_a_header_only(): void
    {
        $response = $this->checkout('')->create(['line_items' => self::LINES], null);

        $this->assertSame(201, $response->status, (string) json_encode($response->body));
        $this->assertMatchesRegularExpression(self::SECRET_SHAPE, $response->headers[self::HEADER]);
        $this->assertStringNotContainsString($response->headers[self::HEADER], (string) json_encode($response->body));
        $this->assertArrayNotHasKey('session_secret', $response->body);
        $this->assertSame(hash('sha256', $response->headers[self::HEADER]), $this->sessions->rows[$response->body['id']]['session_secret_hash']);
    }

    public function test_cart_create_returns_the_secret_in_a_header_only(): void
    {
        $response = $this->cart('')->create(['line_items' => self::LINES], null);

        $this->assertSame(201, $response->status, (string) json_encode($response->body));
        $this->assertMatchesRegularExpression(self::SECRET_SHAPE, $response->headers[self::HEADER]);
        $this->assertStringNotContainsString($response->headers[self::HEADER], (string) json_encode($response->body));
        $this->assertArrayNotHasKey('cart_secret', $response->body);
        $this->assertSame(hash('sha256', $response->headers[self::HEADER]), $this->carts->rows[$response->body['id']]['cart_secret_hash']);
    }

    public function test_cart_checkout_with_the_cart_secret_issues_a_new_session_secret_and_removes_the_cart(): void
    {
        $session = FdTestGoldenRenderer::input('checkout-session.json');
        $session['ucp_version'] = null;
        Db::$row = $session;

        $response = $this->cart(self::SECRET)->checkout(self::CART_ID, null);

        $this->assertSame(201, $response->status, (string) json_encode($response->body));
        $this->assertMatchesRegularExpression(self::SECRET_SHAPE, $response->headers[self::HEADER]);
        $this->assertNotSame(self::SECRET, $response->headers[self::HEADER]);
        $this->assertArrayNotHasKey('session_secret', $response->body);
        $this->assertArrayNotHasKey(self::CART_ID, $this->carts->rows);
        $this->assertCount(1, Db::$inserted);
        $this->assertSame(hash('sha256', $response->headers[self::HEADER]), Db::$inserted[0]['data']['session_secret_hash']);
    }

    public function test_order_read_requires_the_secret_of_the_placing_session(): void
    {
        $service = fn (string $secret) => new OrderService(Context::getContext(), $secret);
        Db::$row = ['id_order' => 1001, 'session_secret_hash' => hash('sha256', self::SECRET)];

        $missing = $service('')->get(1001);
        $wrong = $service('wrong')->get(1001);

        $this->assertSame(401, $missing->status);
        $this->assertSame('session_secret_required', self::code($missing));
        $this->assertSame(404, $wrong->status);
        $this->assertSame('order_not_found', self::code($wrong));
    }

    public static function unusableOrderHashes(): array
    {
        return ['empty' => [''], 'null' => [null], 'malformed' => ['garbage']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unusableOrderHashes')]
    public function test_order_read_rejects_a_row_without_a_usable_stored_hash(?string $stored): void
    {
        Db::$row = ['id_order' => 1001, 'session_secret_hash' => $stored];

        $response = (new OrderService(Context::getContext(), self::SECRET))->get(1001);

        $this->assertSame(404, $response->status);
        $this->assertSame('order_not_found', self::code($response));
    }

    public function test_replay_by_the_same_agent_with_the_same_body_returns_the_stored_session_without_a_secret(): void
    {
        $first = $this->checkout('')->create(['line_items' => self::LINES], 'idem-1');
        $rowsAfterFirst = count($this->sessions->rows);

        $replay = $this->checkout('')->create(['line_items' => self::LINES], 'idem-1');

        $this->assertSame(201, $first->status, (string) json_encode($first->body));
        $this->assertSame(200, $replay->status, (string) json_encode($replay->body));
        $this->assertSame([], $replay->headers);
        $this->assertSame($first->body['id'], $replay->body['id']);
        $this->assertCount($rowsAfterFirst, $this->sessions->rows);
    }

    public function test_replay_with_a_different_body_is_a_conflict(): void
    {
        $this->checkout('')->create(['line_items' => self::LINES], 'idem-1');
        $rowsAfterFirst = count($this->sessions->rows);

        $replay = $this->checkout('')->create(['line_items' => [['item' => ['id' => '101'], 'quantity' => 3]]], 'idem-1');

        $this->assertSame(409, $replay->status, (string) json_encode($replay->body));
        $this->assertSame('idempotency_key_conflict', self::code($replay));
        $this->assertSame([], $replay->headers);
        $this->assertCount($rowsAfterFirst, $this->sessions->rows);
    }

    public function test_the_same_key_from_another_agent_creates_its_own_session(): void
    {
        $first = $this->checkout('')->create(['line_items' => self::LINES], 'idem-1');

        $other = $this->checkout('', null, hash('sha256', 'another-agent'))->create(['line_items' => self::LINES], 'idem-1');

        $this->assertSame(201, $other->status, (string) json_encode($other->body));
        $this->assertNotSame($first->body['id'], $other->body['id']);
        $this->assertMatchesRegularExpression(self::SECRET_SHAPE, $other->headers[self::HEADER]);
    }
}

final class FdTestOwnedCarts extends CartRepository
{
    public array $rows = [];

    public function __construct()
    {
    }

    public function insert(array $data): bool
    {
        $this->rows[$data['cart_uid']] = $data;

        return true;
    }

    public function findByUid(string $uid, int $idShop): ?array
    {
        return $this->rows[$uid] ?? null;
    }

    public function update(string $uid, int $idShop, array $data): bool
    {
        $this->rows[$uid] = array_merge($this->rows[$uid], $data);

        return true;
    }

    public function delete(string $uid, int $idShop): bool
    {
        unset($this->rows[$uid]);

        return true;
    }
}
