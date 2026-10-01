<?php

[, $orig, $inputs, $out, $lane] = $argv;

require_once $orig . '/modules/fdpsucp/tests/bootstrap.php';
require_once __DIR__ . '/capture-stubs.php';
require_once $orig . '/modules/fdpsucp/src/autoload.php';

use FD\PrismUcp\Cart\CartService;
use FD\PrismUcp\Payment\PaymentRegistry;
use FD\PrismUcp\Ucp\Formatter;
use FD\PrismUcp\Ucp\UcpError;

$registry = new PaymentRegistry();
if ($lane === 'prism') {
    require_once $orig . '/modules/fdpsucp/src/Payment/PaymentHandlerInterface.php';
    require_once $orig . '/modules/fdpsprism/src/autoload.php';
    $registry->register(new \FD\PrismPayment\Prism\PrismHandler(new PaymentModule()));
}

$read = static fn (string $name): array => json_decode(file_get_contents($inputs . '/' . $name), true);

$session = $read('checkout-session.json');
$completed = $read('checkout-session-completed.json');
$cart = $read('cart.json');
$errors = $read('errors.json');

$service = (new ReflectionClass(CartService::class))->newInstanceWithoutConstructor();
(new ReflectionProperty(CartService::class, 'context'))->setValue($service, new Context());
$formatCart = new ReflectionMethod(CartService::class, 'formatCart');

$documents = [
    'profile' => Formatter::profile('https://store.test/module/fdpsucp/api', 'Demo Store', $registry),
    'checkout__create' => Formatter::checkoutSession($session, $registry),
    'checkout__complete' => Formatter::completeResponse($completed, new Order(), $registry),
    'cart__create' => $formatCart->invoke($service, $cart),
    'error__invalid_instrument' => UcpError::response('invalid_instrument', $errors['invalid_instrument']['message'], $errors['invalid_instrument']['status'])->body,
];

if (!is_dir($out)) {
    mkdir($out, 0777, true);
}
foreach ($documents as $name => $document) {
    file_put_contents($out . '/' . $name . '.json', json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}
