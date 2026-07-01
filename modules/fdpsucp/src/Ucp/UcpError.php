<?php

namespace FD\PrismUcp\Ucp;

use FD\PrismUcp\Http\Response;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * UCP error envelope. Ported from FD_UCP_Error (woocommerce-ucp).
 */
final class UcpError
{
    public const VERSION = '2026-04-08';

    public static function response(string $code, string $message, int $httpStatus = 400): Response
    {
        return Response::json($httpStatus, [
            'ucp' => [
                'version' => self::VERSION,
                'status' => 'error',
            ],
            'messages' => [
                [
                    'type' => 'error',
                    'code' => $code,
                    'content' => $message,
                    'severity' => 'fatal',
                ],
            ],
        ]);
    }
}
