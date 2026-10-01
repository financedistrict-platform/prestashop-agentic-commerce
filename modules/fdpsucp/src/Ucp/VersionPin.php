<?php

namespace FD\PrismUcp\Ucp;

use FD\PrismUcp\Http\Response;

if (!defined('_PS_VERSION_')) {
    exit;
}

final class VersionPin
{
    public function __construct(
        private VersionResolver $resolver,
        private ?string $agentHeader
    ) {
    }

    public function check(?string $pinned): ?Response
    {
        $context = $this->resolver->resolve($this->agentHeader, $pinned);
        if ($context->rejection() !== null) {
            return $context->rejectionResponse();
        }
        RequestContext::set($context);

        return null;
    }
}
