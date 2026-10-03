<?php

declare(strict_types=1);

use FD\PrismUcp\Ucp\AgentProfileFetcher;
use PHPUnit\Framework\TestCase;

final class AgentProfileFetcherLoopbackTest extends TestCase
{
    private static $server;
    private static int $port;

    public static function setUpBeforeClass(): void
    {
        $probe = stream_socket_server('tcp://127.0.0.1:0');
        self::$port = (int) substr(strrchr((string) stream_socket_get_name($probe, false), ':'), 1);
        fclose($probe);

        self::$server = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . self::$port, __DIR__ . '/../support/redirect-router.php'],
            [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes
        );

        for ($i = 0; $i < 50; $i++) {
            $socket = @fsockopen('127.0.0.1', self::$port);
            if ($socket !== false) {
                fclose($socket);

                return;
            }
            usleep(100000);
        }
        self::fail('loopback server did not start');
    }

    public static function tearDownAfterClass(): void
    {
        if (is_resource(self::$server)) {
            proc_terminate(self::$server);
            proc_close(self::$server);
        }
    }

    protected function setUp(): void
    {
        FdTestStubs::reset();
    }

    private function lookup(string $path): array
    {
        return (new AgentProfileFetcher(true))->lookup('http://127.0.0.1:' . self::$port . $path);
    }

    public function test_trailing_slash_redirect_is_followed(): void
    {
        $this->assertSame('2026-01-23', $this->lookup('/p')['version']);
    }

    public function test_a_second_redirect_is_reported(): void
    {
        $result = $this->lookup('/two-hops');

        $this->assertSame('redirected', $result['reason']);
        $this->assertSame('http://127.0.0.1:' . self::$port . '/p/', $result['location']);
    }

    public function test_cross_origin_redirect_is_reported(): void
    {
        $result = $this->lookup('/away');

        $this->assertSame(['failed' => true, 'reason' => 'redirected', 'location' => 'http://localhost:1/p'], $result);
    }

    public function test_body_cap_is_enforced_after_the_redirect(): void
    {
        $this->assertSame(['failed' => true], $this->lookup('/big'));
    }
}
