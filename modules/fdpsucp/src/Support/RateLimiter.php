<?php

namespace FD\PrismUcp\Support;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Fixed-window per-IP rate limiter, backed by a dedicated DB table.
 *
 * NFR-5 (rate limiting MUST). The original port used \Cache::getInstance(),
 * but PrestaShop's cache is disabled by default and does not persist counters
 * across requests — so the limiter never tripped. A small DB table makes the
 * counter durable and correct regardless of the shop's cache configuration,
 * and the atomic UPSERT is safe under concurrent requests.
 */
final class RateLimiter
{
    private int $maxRequests;
    private int $windowSeconds;

    public function __construct(int $maxRequests = 60, int $windowSeconds = 60)
    {
        $this->maxRequests = $maxRequests;
        $this->windowSeconds = $windowSeconds;
    }

    /**
     * @return array{0:bool,1:int} [allowed, retryAfterSeconds]
     */
    public function check(string $action = 'default'): array
    {
        $table = _DB_PREFIX_ . 'prism_rate_limit';
        $key = pSQL(substr('fd_rl_' . $action . '|' . $this->clientIp(), 0, 191));
        $now = time();
        $win = $this->windowSeconds;
        $db = \Db::getInstance();

        // Atomically bump the counter, resetting the window if the previous one
        // has elapsed. The IF() logic runs server-side in a single statement.
        $db->execute(
            'INSERT INTO `' . $table . '` (`rl_key`, `hits`, `window_start`)
             VALUES ("' . $key . '", 1, ' . $now . ')
             ON DUPLICATE KEY UPDATE
                `hits` = IF(`window_start` + ' . $win . ' <= ' . $now . ', 1, `hits` + 1),
                `window_start` = IF(`window_start` + ' . $win . ' <= ' . $now . ', ' . $now . ', `window_start`)'
        );

        $row = $db->getRow('SELECT `hits`, `window_start` FROM `' . $table . '` WHERE `rl_key` = "' . $key . '"');
        $hits = (int) ($row['hits'] ?? 1);
        $windowStart = (int) ($row['window_start'] ?? $now);

        // Opportunistic cleanup of long-expired rows so the table can't grow
        // without bound (roughly 1 call in 100).
        if (random_int(1, 100) === 1) {
            $db->execute('DELETE FROM `' . $table . '` WHERE `window_start` < ' . ($now - $win));
        }

        if ($hits > $this->maxRequests) {
            $retryAfter = $windowStart + $win - $now;
            return [false, max(1, $retryAfter)];
        }

        return [true, 0];
    }

    private function clientIp(): string
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        return is_string($ip) ? $ip : '0.0.0.0';
    }
}
