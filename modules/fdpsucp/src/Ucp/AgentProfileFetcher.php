<?php

namespace FD\PrismUcp\Ucp;

if (!defined('_PS_VERSION_')) {
    exit;
}

class AgentProfileFetcher
{
    public const CACHE_TTL = 600;
    public const CACHE_MAX_ENTRIES = 1000;
    public const CACHE_PREFIX = 'fdpsucp_profile_';
    public const MAX_BYTES = 131072;
    public const TIMEOUT = 3;
    public const MAX_LOCATION = 512;
    public const REDIRECT_CODES = [301, 302, 303, 307, 308];

    private static array $lru = [];

    private bool $fetched = false;

    public function __construct(private bool $allowLoopbackForTests = false)
    {
    }

    public static function resetCache(): void
    {
        self::$lru = [];
    }

    public static function cachedKeys(): array
    {
        return array_keys(self::$lru);
    }

    public function lookup(string $url): array
    {
        $key = md5($url);
        $cached = $this->cached($key);
        if ($cached !== null) {
            return $cached;
        }

        if ($this->fetched) {
            return ['failed' => true];
        }
        $this->fetched = true;

        $result = $this->fetch($url);
        $this->remember($key, $result);

        return $result;
    }

    private function cached(string $key): ?array
    {
        $entry = self::$lru[$key] ?? null;
        if ($entry !== null) {
            unset(self::$lru[$key]);
            if ($entry['expires'] > time()) {
                self::$lru[$key] = $entry;

                return $entry['value'];
            }
        }

        if (self::sharedCacheEnabled()) {
            $shared = \Cache::getInstance()->get(self::CACHE_PREFIX . $key);
            if (is_array($shared)) {
                $this->remember($key, $shared, false);

                return $shared;
            }
        }

        return null;
    }

    private function remember(string $key, array $value, bool $shared = true): void
    {
        unset(self::$lru[$key]);
        while (count(self::$lru) >= self::CACHE_MAX_ENTRIES) {
            unset(self::$lru[array_key_first(self::$lru)]);
        }
        self::$lru[$key] = ['value' => $value, 'expires' => time() + self::CACHE_TTL];

        if ($shared && self::sharedCacheEnabled()) {
            \Cache::getInstance()->set(self::CACHE_PREFIX . $key, $value, self::CACHE_TTL);
        }
    }

    private static function sharedCacheEnabled(): bool
    {
        return defined('_PS_CACHE_ENABLED_') && _PS_CACHE_ENABLED_ && class_exists('Cache');
    }

    private function fetch(string $url): array
    {
        $target = $this->validatedTarget($url);
        if ($target === null) {
            return ['failed' => true];
        }

        $deadline = microtime(true) + self::TIMEOUT;
        $response = $this->request($url, $target['host'], $target['port'], $target['ip'], $target['scheme'], self::TIMEOUT * 1000);
        if ($response === null) {
            return ['failed' => true];
        }

        if (self::isRedirect($response)) {
            $location = self::withoutFragment($response['location']);
            if (!$this->sameOrigin($location, $target)) {
                return self::redirected($location);
            }
            $remaining = (int) (($deadline - microtime(true)) * 1000);
            if ($remaining <= 0) {
                return ['failed' => true];
            }
            $response = $this->request($location, $target['host'], $target['port'], $target['ip'], $target['scheme'], $remaining);
            if ($response === null) {
                return ['failed' => true];
            }
            if (self::isRedirect($response)) {
                return self::redirected(self::withoutFragment($response['location']));
            }
        }

        if ($response['code'] !== 200 || strlen($response['body']) > self::MAX_BYTES) {
            return ['failed' => true];
        }

        $profile = json_decode($response['body'], true);
        if (!is_array($profile)) {
            return ['failed' => true];
        }

        $version = $profile['ucp']['version'] ?? null;

        return [
            'version' => is_string($version) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $version) ? $version : null,
            'fetched_at' => time(),
        ];
    }

    private static function isRedirect(array $response): bool
    {
        return in_array($response['code'], self::REDIRECT_CODES, true);
    }

    private static function withoutFragment(string $location): string
    {
        $hash = strpos($location, '#');

        return $hash === false ? $location : substr($location, 0, $hash);
    }

    private static function redirected(string $location): array
    {
        return [
            'failed' => true,
            'reason' => 'redirected',
            'location' => $location === '' ? null : substr($location, 0, self::MAX_LOCATION),
        ];
    }

    private function sameOrigin(string $location, array $origin): bool
    {
        if ($location === '' || strtolower((string) parse_url($location, PHP_URL_HOST)) !== $origin['host']) {
            return false;
        }

        $hop = $this->validatedTarget($location);

        return $hop !== null
            && $hop['scheme'] === $origin['scheme']
            && $hop['host'] === $origin['host']
            && $hop['port'] === $origin['port'];
    }

    private function validatedTarget(string $url): ?array
    {
        $parts = parse_url($url);
        if (!is_array($parts)) {
            return null;
        }
        $scheme = strtolower($parts['scheme'] ?? '');
        $host = strtolower($parts['host'] ?? '');
        if ($host === '' || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }

        $loopback = $this->allowLoopbackForTests && $host === '127.0.0.1';
        if ($scheme !== 'https' && !($loopback && $scheme === 'http')) {
            return null;
        }

        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : $this->resolveHost($host);
        if ($ips === []) {
            return null;
        }
        foreach ($ips as $ip) {
            if ($loopback && $ip === '127.0.0.1') {
                continue;
            }
            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return null;
            }
        }

        return [
            'scheme' => $scheme,
            'host' => $host,
            'port' => (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80)),
            'ip' => $ips[0],
        ];
    }

    protected function resolveHost(string $host): array
    {
        $ips = gethostbynamel($host);

        return is_array($ips) ? $ips : [];
    }

    public function curlOptions(string $host, int $port, string $ip, string $scheme, \Closure $sink, int $timeoutMs = self::TIMEOUT * 1000): array
    {
        $pinned = str_contains($ip, ':') ? '[' . $ip . ']' : $ip;

        return [
            CURLOPT_PROTOCOLS => $scheme === 'http' ? CURLPROTO_HTTP : CURLPROTO_HTTPS,
            CURLOPT_RESOLVE => ["$host:$port:$pinned"],
            CURLOPT_TIMEOUT_MS => $timeoutMs,
            CURLOPT_CONNECTTIMEOUT_MS => $timeoutMs,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => 'fd-prestashop-ucp/' . \FdPsUcp::VERSION,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
            CURLOPT_WRITEFUNCTION => $sink,
        ];
    }

    protected function request(string $url, string $host, int $port, string $ip, string $scheme, int $timeoutMs): ?array
    {
        if (!function_exists('curl_init')) {
            return null;
        }

        $body = '';
        $ch = curl_init($url);
        curl_setopt_array($ch, $this->curlOptions($host, $port, $ip, $scheme, static function ($handle, string $chunk) use (&$body): int {
            if (strlen($body) + strlen($chunk) > self::MAX_BYTES) {
                return 0;
            }
            $body .= $chunk;

            return strlen($chunk);
        }, $timeoutMs));

        $ok = curl_exec($ch);
        if ($ok === false) {
            return null;
        }

        return [
            'code' => (int) curl_getinfo($ch, CURLINFO_HTTP_CODE),
            'body' => $body,
            'location' => (string) curl_getinfo($ch, CURLINFO_REDIRECT_URL),
        ];
    }
}
