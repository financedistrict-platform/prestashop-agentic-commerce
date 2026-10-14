<?php

declare(strict_types=1);

use FD\PrismPayment\Prism\PrismClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PrismClientSettleTest extends TestCase
{
    private const SETTLED = '{"success":true,"transaction":"0xabc","network":"eip155:84532"}';

    #[DataProvider('unknownOutcomes')]
    public function test_settle_without_a_usable_answer_is_unknown(?array $transport): void
    {
        $this->assertNull((new FdTestTransportClient($transport))->settle(2, [], []));
    }

    public static function unknownOutcomes(): array
    {
        return [
            'no response' => [null],
            'gateway timeout' => [[504, '']],
            'bad gateway' => [[502, 'upstream']],
            'server error' => [[500, '{"error":"boom"}']],
            'request timeout' => [[408, '']],
            'rate limited' => [[429, '']],
            'html body' => [[200, '<html>proxy</html>']],
            'empty body' => [[200, '']],
        ];
    }

    #[DataProvider('refusals')]
    public function test_settle_refused_before_settling_is_a_definite_rejection(int $code): void
    {
        $result = (new FdTestTransportClient([$code, '{"error":"no"}']))->settle(2, [], []);

        $this->assertFalse($result['success']);
        $this->assertSame("http_$code", $result['errorReason']);
    }

    public static function refusals(): array
    {
        return [[400], [401], [403], [404], [422]];
    }

    public function test_settle_returns_the_gateway_answer(): void
    {
        $result = (new FdTestTransportClient([200, self::SETTLED]))->settle(2, [], []);

        $this->assertTrue($result['success']);
        $this->assertSame('0xabc', $result['transaction']);
    }

    public function test_settle_waits_longer_than_the_gateway_facilitator_timeout(): void
    {
        $client = new FdTestTransportClient([200, self::SETTLED]);

        $client->settle(2, [], []);

        $this->assertSame(45, $client->timeouts['POST /api/v2/payment/settle']);
    }

    public function test_other_calls_keep_treating_any_failure_as_no_answer(): void
    {
        foreach ([400, 404, 500] as $code) {
            $this->assertNull((new FdTestTransportClient([$code, '{"error":"no"}']))->fetchUcpHandlers('2026-08-25'));
        }
        $this->assertNull((new FdTestTransportClient([422, '{}']))->preparePaymentRequirements('1.00', 'USD', 'https://shop.example/c/1', 'Order'));
    }
}

final class FdTestTransportClient extends PrismClient
{
    public array $timeouts = [];

    public function __construct(private ?array $response)
    {
        parent::__construct('https://prism-gw.example', 'key');
    }

    protected function transport(string $method, string $path, ?array $body, int $timeout): ?array
    {
        $this->timeouts["$method $path"] = $timeout;

        return $this->response;
    }
}
