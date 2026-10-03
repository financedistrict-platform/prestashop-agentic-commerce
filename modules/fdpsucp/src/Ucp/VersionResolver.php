<?php

namespace FD\PrismUcp\Ucp;

if (!defined('_PS_VERSION_')) {
    exit;
}

class VersionResolver
{
    public const HOOK = 'actionFdUcpProfileResolution';

    public function __construct(
        private VersionRegistry $versions,
        private AgentProfileFetcher $fetcher
    ) {
    }

    public static function profileUrl(?string $agentHeader): ?string
    {
        if (!is_string($agentHeader) || !preg_match('/profile="([^"]+)"/', $agentHeader, $m)) {
            return null;
        }

        return $m[1];
    }

    public function versions(): VersionRegistry
    {
        return $this->versions;
    }

    public function resolve(?string $agentHeader, ?string $pinned = null): RequestContext
    {
        $current = $this->versions->current();
        $pinned = ($pinned !== null && $pinned !== '') ? $pinned : null;
        $url = self::profileUrl($agentHeader);

        if ($url === null) {
            return $this->context($pinned ?? $current, RequestContext::OUTCOME_NONE);
        }

        $profile = $this->fetcher->lookup($url);
        $declared = empty($profile['failed']) ? ($profile['version'] ?? null) : null;
        $outcome = $this->outcome($profile, $declared);
        $host = strtolower((string) (parse_url($url, PHP_URL_HOST) ?? ''));

        if ($outcome === RequestContext::OUTCOME_MATCHED) {
            if ($pinned !== null && $declared !== $pinned) {
                return $this->reject($declared, $outcome, $host, 422, 'version_unsupported', sprintf('This session is bound to UCP version %s; the agent profile now declares %s.', $pinned, $declared));
            }

            return $this->served($declared, $outcome, $declared, $host);
        }

        if ($outcome === RequestContext::OUTCOME_DISABLED || $outcome === RequestContext::OUTCOME_UNKNOWN) {
            return $this->reject($declared, $outcome, $host, 422, 'version_unsupported', $this->unsupportedMessage($declared));
        }

        if ($outcome === RequestContext::OUTCOME_REDIRECTED) {
            $location = $profile['location'] ?? null;

            return $this->reject($declared, $outcome, $host, 424, 'profile_redirected', self::redirectedMessage($location), $location);
        }

        if ($this->versions->isStrict()) {
            if ($outcome === RequestContext::OUTCOME_UNREACHABLE) {
                return $this->reject($declared, $outcome, $host, 424, 'profile_unreachable', 'Agent profile could not be retrieved.');
            }

            return $this->reject($declared, $outcome, $host, 422, 'profile_malformed', 'Agent profile does not declare a UCP version.');
        }

        $served = $pinned ?? $current;
        self::log(sprintf('ucp_profile_resolution=%s host=%s served=%s', $outcome, $host, $served));

        return $this->served($served, $outcome, $declared, $host);
    }

    public function unsupportedMessage(string $requested): string
    {
        return sprintf(
            'Version %s is not supported. This business implements versions %s.',
            $requested,
            implode(', ', $this->versions->enabled())
        );
    }

    private static function redirectedMessage(?string $location): string
    {
        return $location === null
            ? 'Agent profile URL redirects; use the final URL.'
            : sprintf('Agent profile URL redirects to %s; use the final URL.', $location);
    }

    private function outcome(array $profile, ?string $declared): string
    {
        if (($profile['reason'] ?? null) === 'redirected') {
            return RequestContext::OUTCOME_REDIRECTED;
        }
        if (!empty($profile['failed'])) {
            return RequestContext::OUTCOME_UNREACHABLE;
        }
        if ($declared === null) {
            return RequestContext::OUTCOME_UNDECLARED;
        }
        if (!VersionRegistry::isKnown($declared)) {
            return RequestContext::OUTCOME_UNKNOWN;
        }
        if (!$this->versions->isEnabled($declared)) {
            return RequestContext::OUTCOME_DISABLED;
        }

        return RequestContext::OUTCOME_MATCHED;
    }

    private function served(string $version, string $outcome, ?string $declared, string $host): RequestContext
    {
        \Hook::exec(self::HOOK, ['outcome' => $outcome, 'version' => $version, 'host' => $host]);

        return $this->context($version, $outcome, $declared);
    }

    private function reject(?string $declared, string $outcome, string $host, int $status, string $code, string $message, ?string $location = null): RequestContext
    {
        \Hook::exec(self::HOOK, ['outcome' => $outcome, 'version' => null, 'host' => $host]);
        $line = sprintf('ucp_profile_resolution=%s host=%s rejected=%s', $outcome, $host, $code);
        if ($outcome === RequestContext::OUTCOME_REDIRECTED) {
            $line .= sprintf(' location=%s', (string) $location);
        }
        self::log($line);

        return new RequestContext(
            $this->versions,
            $this->versions->currentWire()->version(),
            $outcome,
            $declared,
            ['status' => $status, 'code' => $code, 'message' => $message]
        );
    }

    private function context(string $version, string $outcome, ?string $declared = null): RequestContext
    {
        $version = VersionRegistry::isKnown($version) ? $version : $this->versions->currentWire()->version();

        return new RequestContext($this->versions, $version, $outcome, $declared);
    }

    private static function log(string $message): void
    {
        \PrestaShopLogger::addLog('[FD UCP] ' . $message, 2);
    }
}
