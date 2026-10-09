<?php

namespace FD\PrismUcp\Checkout;

use FD\PrismUcp\Ucp\UcpAddress;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Builds a transient PrestaShop Cart from a UCP session. The Cart is the
 * pricing/shipping engine and the input to order creation; the canonical
 * state still lives in ps_prism_session (the Cart is disposable).
 */
class CartBuilder
{
    /**
     * Create a saved Cart for the given shop/lang/currency holding the
     * session's line items, with a guest customer + delivery address when the
     * buyer/fulfillment data is present. Returns the Cart (with an id).
     *
     * @param array<string,mixed> $session
     */
    public function build(array $session, \Context $context): \Cart
    {
        $idShop = (int) $session['id_shop'];
        $idLang = (int) $context->language->id;

        $currency = $this->pinCurrency($session, $context);

        $buyer = $this->decode($session['buyer'] ?? null);
        $customer = $this->resolveGuestCustomer($buyer, $idShop, $idLang);

        $cart = new \Cart();
        $cart->id_shop = $idShop;
        $cart->id_shop_group = (int) $context->shop->id_shop_group;
        $cart->id_lang = $idLang;
        $cart->id_currency = $currency;
        $cart->id_customer = (int) $customer->id;
        $cart->id_guest = (int) $context->cookie->id_guest;
        $cart->recyclable = 0;
        $cart->gift = 0;
        if (!$cart->add()) {
            throw new \RuntimeException('The cart could not be created');
        }

        $context->cart = $cart;

        // Attach a delivery address if the session carries one.
        $address = $this->resolveAddress($session, $customer, $idShop);
        if ($address !== null) {
            $cart->id_address_delivery = (int) $address->id;
            $cart->id_address_invoice = (int) $address->id;
            if (!$cart->update()) {
                throw new \RuntimeException('The delivery address could not be saved on the cart');
            }
        }

        foreach ($this->decode($session['line_items'] ?? null) as $li) {
            $this->addLine($cart, $li);
        }

        $this->applySessionCarrier($cart, $session);

        return $cart;
    }

    private function addLine(\Cart $cart, mixed $line): void
    {
        $node = is_array($line) && is_array($line['item'] ?? null) ? $line['item'] : [];
        $idProduct = LineItems::positiveInt($node['id'] ?? null);
        $idVariant = LineItems::variantId($node['variant_id'] ?? null);
        $quantity = LineItems::quantity(is_array($line) ? ($line['quantity'] ?? null) : null);
        if ($idProduct === null || $idVariant === null || $quantity === null) {
            throw new \RuntimeException('The checkout session holds a line item that is not valid');
        }
        if ($cart->updateQty($quantity, $idProduct, $idVariant ?: null) !== true) {
            throw new \RuntimeException("Product $idProduct could not be added to the cart");
        }
    }

    public function pinCurrency(array $session, \Context $context): int
    {
        $idShop = (int) ($session['id_shop'] ?? 0);
        $idCurrency = (int) \Currency::getIdByIsoCode((string) ($session['currency'] ?? ''), $idShop);
        $currency = $idCurrency > 0 ? new \Currency($idCurrency, null, $idShop) : null;
        if (!\Validate::isLoadedObject($currency) || !$currency->active) {
            throw new \UnexpectedValueException('Checkout session currency is not available in this shop');
        }
        $context->currency = $currency;

        return $idCurrency;
    }

    public function applySessionCarrier(\Cart $cart, array $session): void
    {
        $offered = Fulfillment::offeredOptionKeys($cart);
        if ($offered === []) {
            return;
        }
        $selected = Fulfillment::selectedCarrierId($this->decode($session['fulfillment'] ?? null)) . ',';
        $cart->setDeliveryOption([(int) $cart->id_address_delivery => in_array($selected, $offered, true) ? $selected : $offered[0]]);
        if (!$cart->update()) {
            throw new \RuntimeException('The selected carrier could not be saved on the cart');
        }
    }

    /**
     * @return array{subtotal:int,shipping:int,total:int} minor units
     */
    public function totals(\Cart $cart): array
    {
        return [
            'subtotal' => $this->toMinor((float) $cart->getOrderTotal(true, \Cart::ONLY_PRODUCTS)),
            'shipping' => $this->toMinor((float) $cart->getOrderTotal(true, \Cart::ONLY_SHIPPING)),
            'total' => $this->toMinor((float) $cart->getOrderTotal(true, \Cart::BOTH)),
        ];
    }

    public function priceLines(\Cart $cart, array $formatted): ?array
    {
        $inCart = [];
        foreach ($cart->getProducts() ?: [] as $row) {
            $inCart[(int) $row['id_product'] . '-' . (int) $row['id_product_attribute']] = $row;
        }

        $wanted = [];
        foreach ($formatted as $li) {
            $key = $this->lineKey($li);
            $wanted[$key] = ($wanted[$key] ?? 0) + (int) $li['quantity'];
        }
        if (count($wanted) !== count($inCart)) {
            return null;
        }
        foreach ($wanted as $key => $quantity) {
            if (!isset($inCart[$key]['price_wt']) || (int) ($inCart[$key]['cart_quantity'] ?? 0) !== $quantity) {
                return null;
            }
        }

        $priced = [];
        foreach ($formatted as $li) {
            $price = $this->toMinor((float) $inCart[$this->lineKey($li)]['price_wt']);
            $lineTotal = $price * (int) $li['quantity'];
            $li['item']['price'] = $price;
            $li['totals'] = [
                ['type' => 'subtotal', 'amount' => $lineTotal],
                ['type' => 'total', 'amount' => $lineTotal],
            ];
            $priced[] = $li;
        }

        return $priced;
    }

    private function lineKey(array $lineItem): string
    {
        return (int) ($lineItem['item']['id'] ?? 0) . '-' . (int) ($lineItem['item']['variant_id'] ?? 0);
    }

    /**
     * @param array<string,mixed> $buyer
     */
    private function resolveGuestCustomer(array $buyer, int $idShop, int $idLang): \Customer
    {
        $email = (string) ($buyer['email'] ?? '');

        $customer = new \Customer();
        $customer->is_guest = 1;
        $customer->id_shop = $idShop;
        $customer->id_lang = $idLang;
        $customer->email = ($email !== '' && \Validate::isEmail($email))
            ? $email
            : 'agent_' . uniqid('', false) . '@ucp.local';
        $customer->firstname = (string) ($buyer['first_name'] ?? 'Agent');
        $customer->lastname = (string) ($buyer['last_name'] ?? 'Buyer');
        $customer->passwd = \Tools::hash(uniqid('ucp', true));
        if (!$customer->add()) {
            throw new \RuntimeException('The guest customer could not be created');
        }

        return $customer;
    }

    /**
     * @param array<string,mixed> $session
     */
    private function resolveAddress(array $session, \Customer $customer, int $idShop): ?\Address
    {
        $fulfillment = $this->decode($session['fulfillment'] ?? null);
        $dest = $fulfillment['methods'][0]['destinations'][0] ?? null;
        if (!is_array($dest) || empty($dest['address_country'])) {
            return null;
        }

        $ps = UcpAddress::ucpToPs($dest);
        $idCountry = $this->deliveryCountryId($ps['country'], $idShop);
        if ($idCountry === 0) {
            return null;
        }

        $buyer = $this->decode($session['buyer'] ?? null);

        $address = new \Address();
        $address->id_customer = (int) $customer->id;
        $address->id_country = $idCountry;
        $address->alias = 'UCP';
        $address->firstname = (string) ($buyer['first_name'] ?? $customer->firstname ?: 'Agent');
        $address->lastname = (string) ($buyer['last_name'] ?? $customer->lastname ?: 'Buyer');
        $address->address1 = $ps['address1'] ?: 'N/A';
        $address->address2 = $ps['address2'];
        $address->city = $ps['city'] ?: 'N/A';
        $address->postcode = $ps['postcode'];

        $idState = $this->deliveryStateId($ps['state'], $idCountry);
        if ($idState > 0) {
            $address->id_state = $idState;
        }

        if (!$address->add()) {
            throw new \RuntimeException('The delivery address could not be saved');
        }

        return $address;
    }

    public function deliveryCountryId(string $iso, int $idShop): int
    {
        if (!\Validate::isLanguageIsoCode($iso)) {
            return 0;
        }
        $idCountry = (int) \Country::getByIso($iso, true);

        return $idCountry > 0 && (new \Country($idCountry))->isAssociatedToShop($idShop) ? $idCountry : 0;
    }

    public function deliveryStateId(string $state, int $idCountry): int
    {
        if ($state === '') {
            return 0;
        }
        $idState = (int) \State::getIdByIso($state, $idCountry);
        if ($idState > 0) {
            return $idState;
        }
        $idState = (int) \State::getIdByName($state);

        return $idState > 0 && (int) (new \State($idState))->id_country === $idCountry ? $idState : 0;
    }

    private function toMinor(float $amount): int
    {
        return (int) round($amount * 100);
    }

    /** @return array<string,mixed> */
    private function decode(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);
            return is_array($decoded) ? $decoded : [];
        }
        return [];
    }
}
