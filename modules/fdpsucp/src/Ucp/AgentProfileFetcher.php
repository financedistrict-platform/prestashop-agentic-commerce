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
    public const MAX_BYTES = 65536;
    public const TIMEOUT = 3;

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

        $body = $this->request($url, $target['host'], $target['port'], $target['ip'], $target['scheme']);
        if ($body === null || strlen($body) > self::MAX_BYTES) {
            return ['failed' => true];
        }

        $profile = json_decode($body, true);
        if (!is_array($profile)) {
            return ['failed' => true];
        }

        $version = $profile['ucp']['version'] ?? null;

        return [
            'version' => is_string($version) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $version) ? $version : null,
            'fetched_at' => time(),
        ];
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

    public function curlOptions(string $host, int $port, string $ip, string $scheme, \Closure $sink): array
    {
        $pinned = str_contains($ip, ':') ? '[' . $ip . ']' : $ip;

        return [
            CURLOPT_PROTOCOLS => $scheme === 'http' ? CURLPROTO_HTTP : CURLPROTO_HTTPS,
            CURLOPT_RESOLVE => ["$host:$port:$pinned"],
            CURLOPT_TIMEOUT => self::TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => self::TIMEOUT,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => 'fd-prestashop-ucp/' . \FdPsUcp::VERSION,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
            CURLOPT_WRITEFUNCTION => $sink,
        ];
    }

    protected function request(string $url, string $host, int $port, string $ip, string $scheme): ?string
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
        }));

        $ok = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($ok === false || $code !== 200) {
            return null;
        }

        return $body;
    }
}
