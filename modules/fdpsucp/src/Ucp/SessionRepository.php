<?php

namespace FD\PrismUcp\Ucp;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Data access for the canonical `ps_prism_session` table. Every query is
 * scoped to a shop id (multistore isolation, FR-15).
 */
final class SessionRepository
{
    private string $table;

    public function __construct()
    {
        $this->table = _DB_PREFIX_ . 'prism_session';
    }

    /**
     * @param array<string,mixed> $data column => value (already JSON-encoded where needed)
     */
    public function insert(array $data): bool
    {
        return \Db::getInstance()->insert('prism_session', $this->escape($data));
    }

    /**
     * Load by UID, scoped to a shop. Returns null if not found / wrong shop.
     *
     * @return array<string,mixed>|null
     */
    public function findByUid(string $uid, int $idShop): ?array
    {
        $sql = 'SELECT * FROM `' . $this->table . '`
                WHERE `session_uid` = "' . pSQL($uid) . '"
                AND `id_shop` = ' . (int) $idShop;
        $row = \Db::getInstance()->getRow($sql);
        return $row ?: null;
    }

    /**
     * Find a completed session by idempotency key for a shop (NFR-3).
     *
     * @return array<string,mixed>|null
     */
    public function findByIdempotencyKey(string $key, int $idShop): ?array
    {
        if ($key === '') {
            return null;
        }
        $sql = 'SELECT * FROM `' . $this->table . '`
                WHERE `idempotency_key` = "' . pSQL($key) . '"
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
            'prism_session',
            $this->escape($data),
            '`session_uid` = "' . pSQL($uid) . '" AND `id_shop` = ' . (int) $idShop
        );
    }

    /**
     * Atomically claim a session for completion: flip incomplete ->
     * complete_in_progress only if currently incomplete. Returns true if THIS
     * call won the claim (NFR-2, once-only settlement).
     */
    public function claimForCompletion(string $uid, int $idShop): bool
    {
        $sql = 'UPDATE `' . $this->table . '`
                SET `status` = "complete_in_progress", `updated_at` = "' . pSQL(date('Y-m-d H:i:s')) . '"
                WHERE `session_uid` = "' . pSQL($uid) . '"
                AND `id_shop` = ' . (int) $idShop . '
                AND `status` = "incomplete"';
        \Db::getInstance()->execute($sql);
        return \Db::getInstance()->Affected_Rows() === 1;
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
