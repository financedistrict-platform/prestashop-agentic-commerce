<?php

namespace FD\PrismUcp\Support;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Fixed-window per-IP rate limiter, backed by the PrestaShop cache.
 * Ported from FD_Rate_Limiter (woocommerce-ucp). NFR-5 (rate limiting MUST).
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
        $key = 'fd_rl_' . md5($action . '|' . $this->clientIp());
        $now = time();

        $data = \Cache::getInstance()->get($key);
        if (!is_array($data)) {
            \Cache::getInstance()->set($key, ['count' => 1, 'start' => $now], $this->windowSeconds);
            return [true, 0];
        }

        if ($data['count'] >= $this->maxRequests) {
            $retryAfter = $this->windowSeconds - ($now - $data['start']);
            return [false, max(1, $retryAfter)];
        }

        ++$data['count'];
        $remaining = $this->windowSeconds - ($now - $data['start']);
        if ($remaining > 0) {
            \Cache::getInstance()->set($key, $data, $remaining);
        }

        return [true, 0];
    }

    private function clientIp(): string
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        return is_string($ip) ? $ip : '0.0.0.0';
    }
}
