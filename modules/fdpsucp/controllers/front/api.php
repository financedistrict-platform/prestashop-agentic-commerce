<?php
/**
 * UCP shopping-service entry point.
 *
 * The discovery document advertises this controller as the `endpoint`. It is
 * served at /module/fdpsucp/api/<ucp-path> via a web-server rewrite (works with
 * Friendly URLs off, which the Back Office needs); the remainder of the path
 * arrives in the `ucp_path` param, e.g.:
 *   POST /module/fdpsucp/api/catalog/search
 *   POST /module/fdpsucp/api/checkout-sessions
 *   GET  /module/fdpsucp/api/checkout-sessions/{id}
 * (A clean /ucp/v1/<ucp-path> route via hookModuleRoutes is also available when
 * Friendly URLs are enabled.)
 *
 * All routing then happens in FD\PrismUcp\Router. The resolved shop comes
 * from PrestaShop's native domain dispatch (multistore, FR-15).
 */

use FD\PrismUcp\Http\Response;
use FD\PrismUcp\Payment\PaymentRegistry;
use FD\PrismUcp\Router;
use FD\PrismUcp\Support\RateLimiter;
use FD\PrismUcp\Ucp\UcpError;

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once _PS_MODULE_DIR_ . 'fdpsucp/src/autoload.php';

class FdPsUcpApiModuleFrontController extends ModuleFrontController
{
    /** Agents are anonymous (their own token auth applies, not a customer login). */
    public $auth = false;
    /** The bearer token and buyer PII travel on this connection — require TLS. */
    public $ssl = true;

    public function initContent()
    {
        $headers = $this->readHeaders();
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

        // --- Auth (NFR-5): if a token is configured, require it. ---
        $configuredToken = (string) Configuration::get('FDPSUCP_AGENT_TOKEN');
        if ($configuredToken !== '' && !$this->tokenMatches($headers, $configuredToken)) {
            $this->emit(UcpError::response('unauthorized', 'Missing or invalid agent token', 401));
        }

        // --- Rate limit (NFR-5). ---
        [$allowed, $retryAfter] = (new RateLimiter(60, 60))->check('ucp_api');
        if (!$allowed) {
            header('Retry-After: ' . $retryAfter);
            $this->emit(UcpError::response('rate_limited', "Too many requests. Retry in {$retryAfter}s.", 429));
        }

        $path = (string) Tools::getValue('ucp_path', '');
        $body = $this->readJsonBody();
        $fingerprint = hash('sha256', (string) ($headers['ucp-agent'] ?? ''));

        $registry = PaymentRegistry::collect();
        $endpointBase = rtrim($this->context->link->getBaseLink(), '/') . '/module/fdpsucp/api';

        try {
            $router = new Router($this->context, $registry, $endpointBase, $fingerprint);
            $response = $router->dispatch($method, $path, $body, $headers);
        } catch (\Throwable $e) {
            // Log the detail server-side; never leak internal exception text to the
            // caller (NFR-6 — a raw message can expose SQL, file paths, secrets).
            \PrestaShopLogger::addLog(
                '[FD UCP] Unhandled error: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine(),
                3
            );
            $response = UcpError::response('internal_error', 'An unexpected error occurred', 500);
        }

        $this->emit($response);
    }

    private function emit(Response $response): void
    {
        header('Content-Type: application/json');
        // No CORS: agents call this server-to-server. A wildcard ACAO would let
        // any web origin read authenticated responses (token + buyer PII).
        http_response_code($response->status);
        echo json_encode($response->body, JSON_UNESCAPED_SLASHES);
        exit;
    }

    /** @return array<string,mixed> */
    private function readJsonBody(): array
    {
        $raw = file_get_contents('php://input');
        if ($raw === false || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    /** @return array<string,string> lowercased header name => value */
    private function readHeaders(): array
    {
        $headers = [];
        if (function_exists('getallheaders')) {
            foreach (getallheaders() as $name => $value) {
                $headers[strtolower((string) $name)] = (string) $value;
            }
            return $headers;
        }
        foreach ($_SERVER as $key => $value) {
            if (strncmp($key, 'HTTP_', 5) === 0) {
                $name = strtolower(str_replace('_', '-', substr($key, 5)));
                $headers[$name] = (string) $value;
            }
        }
        return $headers;
    }

    /** @param array<string,string> $headers */
    private function tokenMatches(array $headers, string $token): bool
    {
        $auth = $headers['authorization'] ?? '';
        if (stripos($auth, 'bearer ') === 0) {
            return hash_equals($token, trim(substr($auth, 7)));
        }
        if (isset($headers['ucp-agent-token'])) {
            return hash_equals($token, $headers['ucp-agent-token']);
        }
        return false;
    }
}
