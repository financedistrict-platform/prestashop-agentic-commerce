<?php

namespace FD\PrismUcp\Http;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * A transport-agnostic HTTP result: status code + JSON body.
 * The front controller turns this into an actual HTTP response.
 */
final class Response
{
    public int $status;
    /** @var array<string,mixed> */
    public array $body;

    /** @param array<string,mixed> $body */
    public function __construct(int $status, array $body)
    {
        $this->status = $status;
        $this->body = $body;
    }

    /** @param array<string,mixed> $body */
    public static function json(int $status, array $body): self
    {
        return new self($status, $body);
    }
}
