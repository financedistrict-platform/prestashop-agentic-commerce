<?php

namespace FD\PrismUcp\Support;

if (!defined('_PS_VERSION_')) {
    exit;
}

final class AgentTokenGate
{
    public static function allows(string $configuredToken, array $headers): bool
    {
        if ($configuredToken === '') {
            return false;
        }

        $auth = $headers['authorization'] ?? '';
        if (stripos($auth, 'bearer ') === 0) {
            return hash_equals($configuredToken, trim(substr($auth, 7)));
        }
        if (isset($headers['ucp-agent-token'])) {
            return hash_equals($configuredToken, $headers['ucp-agent-token']);
        }

        return false;
    }
}
