<?php

declare(strict_types=1);

use FD\PrismUcp\Ucp\RequestContext;
use FD\PrismUcp\Ucp\UcpError;
use FD\PrismUcp\Ucp\Wire\Wire20260825;
use PHPUnit\Framework\TestCase;

/**
 * Ported from woocommerce-ucp UcpErrorTest. PrestaShop's UcpError::response
 * returns a transport-agnostic Response value object (->status / ->body)
 * rather than a WP_REST_Response.
 */
final class UcpErrorTest extends TestCase
{
    protected function setUp(): void
    {
        FdTestStubs::reset();
    }

    public function test_error_response_structure(): void
    {
        $response = UcpError::response('test_code', 'Something went wrong', 422);

        $this->assertSame(422, $response->status);

        $body = $response->body;
        $this->assertSame('error', $body['ucp']['status']);
        $this->assertSame('2026-04-08', $body['ucp']['version']);
        $this->assertSame('test_code', $body['messages'][0]['code']);
        $this->assertSame('Something went wrong', $body['messages'][0]['content']);
        $this->assertSame('fatal', $body['messages'][0]['severity']);
    }

    public function test_2026_08_25_error_keeps_its_version_and_uses_the_spec_severity(): void
    {
        $body = (new Wire20260825())->error('test_code', 'Something went wrong');

        $this->assertSame('error', $body['ucp']['status']);
        $this->assertSame('2026-08-25', $body['ucp']['version']);
        $this->assertSame('test_code', $body['messages'][0]['code']);
        $this->assertSame('unrecoverable', $body['messages'][0]['severity']);
    }

    public function test_error_follows_the_resolved_request_version(): void
    {
        RequestContext::set(RequestContext::forVersion('2026-01-23'));

        $body = UcpError::response('test_code', 'Something went wrong')->body;

        $this->assertSame('2026-01-23', $body['ucp']['version']);
        $this->assertSame('requires_escalation', $body['status']);
        $this->assertSame('requires_buyer_input', $body['messages'][0]['severity']);
    }

    public function test_default_status_is_400(): void
    {
        $this->assertSame(400, UcpError::response('bad_input', 'Bad')->status);
    }
}
