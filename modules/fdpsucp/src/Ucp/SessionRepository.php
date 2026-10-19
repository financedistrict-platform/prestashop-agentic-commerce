<?php

namespace FD\PrismUcp\Ucp;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Data access for the canonical `ps_prism_session` table. Every query is
 * scoped to a shop id (multistore isolation, FR-15).
 */
class SessionRepository
{
    public const CLAIM_STALE_AFTER = 60;

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

    /** @return array<string,mixed>|null */
    public function findByIdempotencyKey(string $key, int $idShop, string $fingerprint): ?array
    {
        if ($key === '') {
            return null;
        }
        $sql = 'SELECT * FROM `' . $this->table . '`
                WHERE `idempotency_key` = "' . pSQL($key, true) . '"
                AND `agent_fingerprint` = "' . pSQL($fingerprint) . '"
                AND `id_shop` = ' . (int) $idShop . '
                ORDER BY `id_prism_session` ASC
                LIMIT 1';
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
     * Atomically claim a session for completion: flip incomplete,
     * payment_pending or an abandoned complete_in_progress (older than
     * CLAIM_STALE_AFTER) -> complete_in_progress. Returns true if THIS call
     * won the claim (NFR-2, once-only settlement).
     */
    public function claimForCompletion(string $uid, int $idShop): bool
    {
        $sql = 'UPDATE `' . $this->table . '`
                SET `status` = "complete_in_progress", `updated_at` = "' . pSQL(date('Y-m-d H:i:s')) . '"
                WHERE `session_uid` = "' . pSQL($uid) . '"
                AND `id_shop` = ' . (int) $idShop . '
                AND (`status` IN ("incomplete", "payment_pending")
                    OR (`status` = "complete_in_progress" AND `updated_at` <= "' . pSQL(date('Y-m-d H:i:s', time() - self::CLAIM_STALE_AFTER)) . '"))';
        \Db::getInstance()->execute($sql);
        return \Db::getInstance()->Affected_Rows() === 1;
    }

    public function cancelIfIncomplete(string $uid, int $idShop): bool
    {
        $sql = 'UPDATE `' . $this->table . '`
                SET `status` = "canceled", `updated_at` = "' . pSQL(date('Y-m-d H:i:s')) . '"
                WHERE `session_uid` = "' . pSQL($uid) . '"
                AND `id_shop` = ' . (int) $idShop . '
                AND `status` = "incomplete"';
        \Db::getInstance()->execute($sql);

        return \Db::getInstance()->Affected_Rows() === 1;
    }

    /** @param array<string,mixed> $session */
    public static function claimAbandoned(array $session): bool
    {
        $updatedAt = strtotime((string) ($session['updated_at'] ?? ''));

        return ($session['status'] ?? '') === 'complete_in_progress'
            && $updatedAt !== false
            && $updatedAt <= time() - self::CLAIM_STALE_AFTER;
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
