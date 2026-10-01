<?php

declare(strict_types=1);

use FD\PrismUcp\Ucp\AgentProfileFetcher;
use PHPUnit\Framework\TestCase;

final class AgentProfileFetcherTest extends TestCase
{
    protected function setUp(): void
    {
        FdTestStubs::reset();
    }

    private function fetcher(array $ips, ?string $body = null, bool $loopback = false): AgentProfileFetcher
    {
        return new class($ips, $body, $loopback) extends AgentProfileFetcher {
            public static array $requests = [];

            public function __construct(private array $ips, private ?string $body, bool $loopback)
            {
                parent::__construct($loopback);
            }

            protected function resolveHost(string $host): array
            {
                return $this->ips;
            }

            protected function request(string $url, string $host, int $port, string $ip, string $scheme): ?string
            {
                self::$requests[] = compact('url', 'host', 'port', 'ip', 'scheme');

                return $this->body;
            }
        };
    }

    private function declaring(string $version): string
    {
        return (string) json_encode(['ucp' => ['version' => $version]]);
    }

    public function test_plain_http_is_rejected_without_a_request(): void
    {
        $fetcher = $this->fetcher(['93.184.216.34'], $this->declaring('2026-08-25'));
        $fetcher::$requests = [];

        $this->assertTrue($fetcher->lookup('http://agent.example/p')['failed']);
        $this->assertSame([], $fetcher::$requests);
    }

    public function test_private_and_loopback_hosts_are_rejected_without_the_test_flag(): void
    {
        foreach (['10.0.0.5', '127.0.0.1', '169.254.169.254', '192.168.1.2'] as $ip) {
            FdTestStubs::reset();
            $fetcher = $this->fetcher([$ip], $this->declaring('2026-08-25'));
            $fetcher::$requests = [];

            $this->assertTrue($fetcher->lookup('https://agent.example/p')['failed'], $ip);
            $this->assertSame([], $fetcher::$requests, $ip);
        }
        $this->assertTrue($this->fetcher([])->lookup('http://127.0.0.1/p')['failed']);
    }

    public function test_any_private_address_among_several_rejects_the_host(): void
    {
        $this->assertTrue($this->fetcher(['93.184.216.34', '10.0.0.5'])->lookup('https://agent.example/p')['failed']);
    }

    public function test_loopback_is_allowed_only_with_the_test_flag(): void
    {
        $result = $this->fetcher([], $this->declaring('2026-08-25'), true)->lookup('http://127.0.0.1/p');

        $this->assertSame('2026-08-25', $result['version']);
    }

    public function test_public_host_is_requested_on_the_validated_ip(): void
    {
        $fetcher = $this->fetcher(['93.184.216.34'], $this->declaring('2026-08-25'));
        $fetcher::$requests = [];

        $result = $fetcher->lookup('https://agent.example/p');

        $this->assertSame('2026-08-25', $result['version']);
        $this->assertSame(['url' => 'https://agent.example/p', 'host' => 'agent.example', 'port' => 443, 'ip' => '93.184.216.34', 'scheme' => 'https'], $fetcher::$requests[0]);
    }

    public function test_curl_options_pin_the_ip_and_harden_the_request(): void
    {
        $options = (new AgentProfileFetcher())->curlOptions('agent.example', 443, '93.184.216.34', 'https', static fn (): int => 0);

        $this->assertSame(CURLPROTO_HTTPS, $options[CURLOPT_PROTOCOLS]);
        $this->assertSame(['agent.example:443:93.184.216.34'], $options[CURLOPT_RESOLVE]);
        $this->assertSame(AgentProfileFetcher::TIMEOUT, $options[CURLOPT_TIMEOUT]);
        $this->assertFalse($options[CURLOPT_FOLLOWLOCATION]);
        $this->assertSame('fd-prestashop-ucp/' . FdPsUcp::VERSION, $options[CURLOPT_USERAGENT]);
    }

    public function test_failure_is_cached_and_not_retried(): void
    {
        $first = $this->fetcher(['93.184.216.34'], null);
        $first::$requests = [];

        $this->assertTrue($first->lookup('https://agent.example/p')['failed']);
        $this->assertTrue($this->fetcher(['93.184.216.34'], $this->declaring('2026-08-25'))->lookup('https://agent.example/p')['failed']);
        $this->assertCount(1, $first::$requests);
    }

    public function test_success_is_cached_for_later_requests(): void
    {
        $first = $this->fetcher(['93.184.216.34'], $this->declaring('2026-01-23'));
        $first::$requests = [];

        $first->lookup('https://agent.example/p');
        $second = $this->fetcher(['93.184.216.34'], null)->lookup('https://agent.example/p');

        $this->assertSame('2026-01-23', $second['version']);
        $this->assertCount(1, $first::$requests);
    }

    public function test_one_fetch_per_request(): void
    {
        $fetcher = $this->fetcher(['93.184.216.34'], $this->declaring('2026-01-23'));
        $fetcher::$requests = [];

        $fetcher->lookup('https://a.example/p');
        $this->assertTrue($fetcher->lookup('https://b.example/p')['failed']);
        $this->assertCount(1, $fetcher::$requests);
    }

    public function test_cache_is_bounded(): void
    {
        for ($i = 0; $i <= AgentProfileFetcher::CACHE_MAX_ENTRIES; $i++) {
            $this->fetcher(['10.0.0.1'])->lookup("https://agent$i.example/p");
        }

        $this->assertCount(AgentProfileFetcher::CACHE_MAX_ENTRIES, AgentProfileFetcher::cachedKeys());
        $this->assertNotContains(md5('https://agent0.example/p'), AgentProfileFetcher::cachedKeys());
    }

    public function test_oversized_or_invalid_body_fails(): void
    {
        $this->assertTrue($this->fetcher(['93.184.216.34'], str_repeat(' ', AgentProfileFetcher::MAX_BYTES + 1) . '{}')->lookup('https://agent.example/big')['failed']);
        FdTestStubs::reset();
        $this->assertTrue($this->fetcher(['93.184.216.34'], '<html>')->lookup('https://agent.example/html')['failed']);
    }

    public function test_credentials_in_url_are_rejected(): void
    {
        $this->assertTrue($this->fetcher(['93.184.216.34'], $this->declaring('2026-08-25'))->lookup('https://user:pw@agent.example/p')['failed']);
    }
}
