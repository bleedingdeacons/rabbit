<?php

declare(strict_types=1);

namespace Rabbit\Transport\Interfaces;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Contract for the HTTP layer a driver uses to talk to its provider.
 *
 * Rabbit defines this so that:
 *
 *  - Drivers can depend on the interface, not on `wp_remote_post`
 *    directly, which makes them testable without standing up a fake
 *    WP HTTP API.
 *  - Operators can swap in a different transport (e.g. one that
 *    wraps Guzzle, or one that signs requests) without forking the
 *    driver.
 *
 * The contract is deliberately small. We pass headers and a body
 * (already-encoded JSON, form data, whatever the provider wants); the
 * transport returns a plain associative array rather than a value
 * object so the contract stays no-dependency. The keys are:
 *  - `status`  int       HTTP status code
 *  - `body`    string    response body (decoded text)
 *  - `headers` array     associative array of response headers
 *                        (lower-cased keys)
 *
 * Implementations SHOULD throw {@see TransportException} on network
 * failures (timeouts, DNS, TLS, connection refused). HTTP status
 * codes — including 4xx and 5xx — are NOT errors at this layer;
 * callers decide what to do with them.
 */
interface HttpTransport
{
    /**
     * @param string $method                'GET', 'POST', 'PUT', 'DELETE'…
     * @param string $url                   Absolute URL.
     * @param array<string,string> $headers Header name → value. Case-insensitive
     *                                       on send; lower-cased on response.
     * @param string $body                  Pre-encoded request body. Empty
     *                                       string for GET / DELETE / HEAD.
     * @return array{status:int,body:string,headers:array<string,string>}
     *
     * @throws TransportException On a network-layer failure.
     */
    public function request(string $method, string $url, array $headers = [], string $body = ''): array;
}
