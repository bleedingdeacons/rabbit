<?php

declare(strict_types=1);

namespace Rabbit\Transport\Interfaces;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Builds {@see HttpTransport} instances.
 *
 * Rabbit defines this so that drivers (and the container bindings
 * that wire them up) depend on the *idea* of "give me a transport"
 * rather than on a concrete class such as
 * {@see \Rabbit\Transport\WpHttpTransport}. Two things fall out of that:
 *
 *  - Drivers stay testable: a test can hand a service a factory that
 *    returns a scripted fake transport, without anyone calling `new`
 *    on a WP-specific class.
 *  - Operators can swap the whole transport implementation (Guzzle,
 *    a signing wrapper, a recording proxy) by binding a different
 *    factory, without touching driver code.
 *
 * Per-call overrides exist for the cases a driver legitimately needs
 * to bend the defaults. Anything not overridden falls back to the
 * factory's configured defaults.
 */
interface HttpTransportFactory
{
    /**
     * Build a fresh {@see HttpTransport}.
     *
     * Every parameter is optional; when omitted, the factory supplies
     * its own configured default. They mirror the knobs a transport
     * commonly needs:
     *
     * @param bool|null $verifyTls      Verify the provider's TLS
     *                                  certificate. Null → factory default.
     * @param int|null  $timeoutSeconds Per-request timeout in seconds.
     *                                  Null → factory default.
     * @param int|null  $maxRedirects   Redirect-follow depth. Pass 0 to
     *                                  see a raw 3xx response. Null →
     *                                  factory default.
     */
    public function create(
        ?bool $verifyTls = null,
        ?int $timeoutSeconds = null,
        ?int $maxRedirects = null,
    ): HttpTransport;
}
