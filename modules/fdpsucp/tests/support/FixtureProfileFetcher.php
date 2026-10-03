<?php

use FD\PrismUcp\Ucp\AgentProfileFetcher;

final class FdTestFixtureProfileFetcher extends AgentProfileFetcher
{
    public array $requested = [];

    public function __construct(private array $bodies, private array $ips = ['93.184.216.34'])
    {
        parent::__construct();
    }

    public static function declaring(?string $version): string
    {
        return (string) json_encode(['ucp' => $version === null ? ['capabilities' => []] : ['version' => $version]]);
    }

    protected function resolveHost(string $host): array
    {
        return $this->ips;
    }

    protected function request(string $url, string $host, int $port, string $ip, string $scheme, int $timeoutMs): ?array
    {
        $this->requested[] = $url;
        $response = $this->bodies[$url] ?? null;

        return is_string($response) ? ['code' => 200, 'body' => $response, 'location' => ''] : $response;
    }
}
