<?php

namespace FD\PrismUcp\Cart;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Data access for the `ps_prism_cart` table — the pre-checkout cart resource
 * (dev.ucp.shopping.cart). Mirrors SessionRepository: every query is scoped to
 * a shop id (multistore isolation, FR-15). A cart is a lightweight holder of
 * line items; it is converted into a canonical ps_prism_session on checkout.
 */
final class CartRepository
{
    private string $table;

    public function __construct()
    {
        $this->table = _DB_PREFIX_ . 'prism_cart';
    }

    /**
     * @param array<string,mixed> $data column => value (already JSON-encoded where needed)
     */
    public function insert(array $data): bool
    {
        return \Db::getInstance()->insert('prism_cart', $this->escape($data));
    }

    /**
     * Load by UID, scoped to a shop. Returns null if not found / wrong shop.
     *
     * @return array<string,mixed>|null
     */
    public function findByUid(string $uid, int $idShop): ?array
    {
        $sql = 'SELECT * FROM `' . $this->table . '`
                WHERE `cart_uid` = "' . pSQL($uid) . '"
                AND `id_shop` = ' . (int) $idShop;
        $row = \Db::getInstance()->getRow($sql);

        return $row ?: null;
    }

    /**
     * @param array<string,mixed> $data column => value
     */
    public function update(string $uid, int $idShop, array $data): bool
    {
        return \Db::getInstance()->update(
            'prism_cart',
            $this->escape($data),
            '`cart_uid` = "' . pSQL($uid) . '" AND `id_shop` = ' . (int) $idShop
        );
    }

    public function delete(string $uid, int $idShop): bool
    {
        return \Db::getInstance()->delete(
            'prism_cart',
            '`cart_uid` = "' . pSQL($uid) . '" AND `id_shop` = ' . (int) $idShop
        );
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private function escape(array $data): array
    {
        $escaped = [];
        foreach ($data as $key => $value) {
            $escaped[$key] = $value === null ? null : pSQL((string) $value, true);
        }

        return $escaped;
    }
}
