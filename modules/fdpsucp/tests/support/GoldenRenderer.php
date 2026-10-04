<?php

use FD\PrismPayment\Prism\PrismClient;
use FD\PrismPayment\Prism\PrismHandler;
use FD\PrismUcp\Cart\CartService;
use FD\PrismUcp\Payment\PaymentRegistry;
use FD\PrismUcp\Ucp\RequestContext;
use FD\PrismUcp\Ucp\UcpError;
use FD\PrismUcp\Ucp\VersionRegistry;

final class FdTestRecordedPrismClient extends PrismClient
{
    public array $paths = [];

    public function __construct(private ?string $body)
    {
        parent::__construct('https://gw.example', 'test-key');
    }

    protected function request(string $method, string $path, ?array $body, int $timeout): ?array
    {
        $this->paths[] = "$method $path";
        $decoded = $this->body === null ? null : json_decode($this->body, true);

        return is_array($decoded) ? $decoded : null;
    }
}

final class FdTestGoldenRenderer
{
    public const FLAGS = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES;
    public const ENDPOINT = 'https://store.test/module/fdpsucp/api';
    public const STORE_BASE = 'https://store.test';

    public static function fixtures(): string
    {
        return dirname(__DIR__) . '/fixtures';
    }

    public static function input(string $name): array
    {
        return json_decode(file_get_contents(self::fixtures() . '/ucp/inputs/' . $name), true);
    }

    public static function names(string $version): array
    {
        $names = ['profile', 'checkout__create', 'checkout__complete', 'cart__create', 'error__invalid_instrument'];
        $wire = (new VersionRegistry($version, []))->wire($version);

        return $wire->supports('cart') ? $names : array_values(array_diff($names, ['cart__create']));
    }

    public static function render(string $version, PaymentRegistry $registry, array $supported = []): array
    {
        $context = new RequestContext(new VersionRegistry($version, $supported), $version);
        RequestContext::set($context);
        $wire = $context->wire();
        $errors = self::input('errors.json');

        $documents = [
            'profile' => $wire->profile(self::ENDPOINT, 'Demo Store', $registry, $context->supportedVersionsMap(self::STORE_BASE)),
            'checkout__create' => $wire->checkoutSession(self::input('checkout-session.json'), $registry),
            'checkout__complete' => $wire->completeResponse(self::input('checkout-session-completed.json'), new Order(), $registry),
        ];

        if ($wire->supports('cart')) {
            $service = (new ReflectionClass(CartService::class))->newInstanceWithoutConstructor();
            (new ReflectionProperty(CartService::class, 'context'))->setValue($service, new Context());
            $documents['cart__create'] = (new ReflectionMethod(CartService::class, 'formatCart'))->invoke($service, self::input('cart.json'));
        }

        $documents['error__invalid_instrument'] = UcpError::response(
            'invalid_instrument',
            $errors['invalid_instrument']['message'],
            $errors['invalid_instrument']['status']
        )->body;

        RequestContext::set(null);

        return array_map(static fn ($document): string => json_encode($document, self::FLAGS), $documents);
    }

    public static function prismRegistry(?string $recorded, ?FdTestRecordedPrismClient &$client = null): PaymentRegistry
    {
        Configuration::$values['FDPSPRISM_API_URL'] = 'https://gw.example';
        Configuration::$values['FDPSPRISM_API_KEY'] = 'test-key';
        $client = new FdTestRecordedPrismClient($recorded === null ? null : file_get_contents(self::fixtures() . '/prism/' . $recorded));
        $registry = new PaymentRegistry();
        $registry->register(new PrismHandler(new PaymentModule(), $client));

        return $registry;
    }
}
