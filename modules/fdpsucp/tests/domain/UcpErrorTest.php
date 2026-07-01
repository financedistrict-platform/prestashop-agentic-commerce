<?php

declare(strict_types=1);

use FD\PrismUcp\Ucp\UcpError;
use PHPUnit\Framework\TestCase;

/**
 * Ported from woocommerce-ucp UcpErrorTest. PrestaShop's UcpError::response
 * returns a transport-agnostic Response value object (->status / ->body)
 * rather than a WP_REST_Response.
 */
final class UcpErrorTest extends TestCase
{
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

    public function test_default_status_is_400(): void
    {
        $this->assertSame(400, UcpError::response('bad_input', 'Bad')->status);
    }
}
