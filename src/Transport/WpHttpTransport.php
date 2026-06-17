<?php

declare(strict_types=1);

namespace Rabbit\Transport;

if (!defined('ABSPATH')) {
    exit;
}

use Rabbit\Transport\Interfaces\HttpTransport;
use Rabbit\Transport\Interfaces\TransportException;

/**
 * WordPress HTTP API implementation of Rabbit's {@see HttpTransport}.
 *
 * Rabbit ships this as the default transport so that drivers don't
 * each have to reimplement WP-HTTP plumbing. Most message providers are
 * stateless JSON-over-HTTPS APIs authenticated with a bearer token (the
 * WhatsApp Cloud API is exactly this), so the per-instance cookie jar
 * below typically stays empty — but it is kept for generality so a
 * driver whose provider *does* use session cookies (a GET-then-POST
 * flow) still works without managing state by hand.
 *
 * We use WP's HTTP API rather than a raw cURL handle deliberately: it
 * honours host-level proxy configuration, the site's CA bundle, and
 * any `http_request_args` filters an operator relies on.
 *
 * Failure-mode contract (from {@see HttpTransport}):
 *  - Network-layer failures (timeout, DNS, TLS handshake, connection
 *    refused) surface as a {@see \WP_Error} from `wp_remote_request()`
 *    and are re-thrown as {@see TransportException}.
 *  - HTTP status codes — including 3xx, 4xx and 5xx — are NOT errors
 *    here. They come back in the response array and the driver decides
 *    what to do with them (a 4xx from a provider usually carries a
 *    structured error body the driver wants to parse).
 *
 * Request headers and bodies can carry credentials (a bearer token, a
 * message body that is personal data), so they are deliberately kept
 * out of the log context — only method, URL, byte counts and cookie
 * *names* are recorded, never values.
 */
final class WpHttpTransport implements HttpTransport
{
    use \Rabbit\Logger\HasLogger;

    /**
     * Session cookie jar, keyed by cookie name so a later Set-Cookie
     * for the same name overrides the earlier value.
     *
     * @var array<string,\WP_Http_Cookie>
     */
    private array $cookies = [];

    public function __construct(
        private readonly bool $verifyTls = true,
        private readonly int $timeoutSeconds = 15,
        private readonly int $maxRedirects = 5,
        private readonly string $userAgent = 'Rabbit (WordPress member-messaging transport)',
        /**
         * Optional log-channel override. This transport is generic —
         * Rabbit ships it as the default for any driver — so by default
         * it logs to its own class-name channel ("wphttptransport").
         * A driver that wants the transport's HTTP logging to appear
         * under the driver's/plugin's own channel passes its channel name
         * here, e.g. WhatsApp passes "whatsapp". Empty string means "use
         * the default class-name channel".
         */
        private readonly string $logChannel = '',
    ) {
    }

    /**
     * Resolve the Sentinel channel for this instance's HTTP logging.
     * When a per-instance {@see $logChannel} override was supplied we
     * use it (so the line is attributed to the owning plugin); without
     * one we defer to the trait's default class-name channel. Returns
     * null when no logger is available, so callers stay null-safe.
     */
    private function channel(): ?\Sentinel_Log_Channel
    {
        if ($this->logChannel === '') {
            return self::log();
        }
        if (!function_exists('wp_log')) {
            return null;
        }
        return wp_log($this->logChannel);
    }

    /**
     * @param array<string,string> $headers
     * @return array{status:int,body:string,headers:array<string,string>}
     *
     * @throws TransportException
     */
    public function request(string $method, string $url, array $headers = [], string $body = ''): array
    {
        $args = [
            'method'      => strtoupper($method),
            'timeout'     => $this->timeoutSeconds,
            'redirection' => $this->maxRedirects,
            'sslverify'   => $this->verifyTls,
            'httpversion' => '1.1',
            'user-agent'  => $this->userAgent,
            'headers'     => $this->prepareRequestHeaders($headers),
            'cookies'     => array_values($this->cookies),
        ];

        // Only attach a body when there is one. A GET/HEAD/DELETE with
        // an empty string body is the common case and some servers
        // behave oddly if handed an empty entity body.
        if ($body !== '') {
            $args['body'] = $body;
        }

        // Note: the request body and headers can carry the bearer token
        // and personal data, so they are deliberately kept out of the log
        // context. Only the method, URL, byte count and the *names* of
        // the cookies being replayed are recorded — never values.
        $this->channel()?->debug('HTTP request', [
            'method' => strtoupper($method),
            'url' => $url,
            'body_bytes' => strlen($body),
            'cookies_sent' => array_keys($this->cookies),
        ]);

        $response = wp_remote_request($url, $args);

        if (is_wp_error($response)) {
            // Network-layer failure — DNS, TLS, timeout, refused. The
            // WP_Error code (e.g. 'http_request_failed') is preserved
            // in the message so the operator gets a usable diagnostic.
            $this->channel()?->error('HTTP request failed at the network layer', [
                'method' => strtoupper($method),
                'url' => $url,
                'error' => $response->get_error_message(),
            ]);
            throw new TransportException(
                'HTTP request to ' . $url . ' failed: ' . $response->get_error_message()
            );
        }

        // Capture any cookies this response set BEFORE returning, so a
        // (rare) session-based provider's next call is authenticated.
        $cookiesBefore = array_keys($this->cookies);
        $this->captureCookies($response);
        $newCookies = array_values(array_diff(array_keys($this->cookies), $cookiesBefore));

        $status = (int) wp_remote_retrieve_response_code($response);
        $responseBody = (string) wp_remote_retrieve_body($response);
        $normalisedHeaders = $this->normaliseResponseHeaders($response);

        $this->channel()?->debug('HTTP response', [
            'method' => strtoupper($method),
            'url' => $url,
            'status' => $status,
            'body_bytes' => strlen($responseBody),
            'set_cookie_names' => $newCookies,
        ]);

        return [
            'status'  => $status,
            'body'    => $responseBody,
            'headers' => $normalisedHeaders,
        ];
    }

    /**
     * Read-only view of the current jar as name → value pairs. Exposed
     * for diagnostics and tests.
     *
     * @return array<string,string>
     */
    public function cookies(): array
    {
        $out = [];
        foreach ($this->cookies as $name => $cookie) {
            $out[$name] = (string) $cookie->value;
        }
        return $out;
    }

    // -- internals --------------------------------------------------------

    /**
     * Merge caller headers over our defaults. We supply an `Accept`
     * default so the provider serves JSON, but the caller wins on any
     * header it sets (the driver sets `Content-Type` and `Authorization`
     * for its requests, for instance).
     *
     * @param array<string,string> $headers
     * @return array<string,string>
     */
    private function prepareRequestHeaders(array $headers): array
    {
        $defaults = [
            'Accept' => 'application/json, */*;q=0.8',
        ];

        // Caller headers take precedence. Case differences (Accept vs
        // accept) are harmless — WP's HTTP layer treats header names
        // case-insensitively on send.
        return array_merge($defaults, $headers);
    }

    /**
     * Parse this response's Set-Cookie headers (via WP, which handles
     * the multi-cookie and attribute-parsing edge cases) and fold them
     * into the jar. A cookie with an empty name is ignored.
     *
     * @param array<string,mixed>|\WP_Error $response
     */
    private function captureCookies($response): void
    {
        $cookies = wp_remote_retrieve_cookies($response);
        if (!is_array($cookies)) {
            return;
        }
        foreach ($cookies as $cookie) {
            if (!$cookie instanceof \WP_Http_Cookie) {
                continue;
            }
            $name = (string) $cookie->name;
            if ($name === '') {
                continue;
            }
            $this->cookies[$name] = $cookie;
        }
    }

    /**
     * Coerce WP's case-insensitive header dictionary into the plain
     * `array<string,string>` the contract promises, with lower-cased
     * keys. Multi-value headers (which WP may hand back as an array)
     * are joined with ", " so the value stays a string.
     *
     * @param array<string,mixed>|\WP_Error $response
     * @return array<string,string>
     */
    private function normaliseResponseHeaders($response): array
    {
        $headers = wp_remote_retrieve_headers($response);

        // wp_remote_retrieve_headers() returns a case-insensitive
        // dictionary object on success and '' if headers are absent.
        if (is_object($headers) && method_exists($headers, 'getAll')) {
            $headers = $headers->getAll();
        }
        if (!is_array($headers)) {
            return [];
        }

        $out = [];
        foreach ($headers as $name => $value) {
            $key = strtolower((string) $name);
            $out[$key] = is_array($value) ? implode(', ', array_map('strval', $value)) : (string) $value;
        }
        return $out;
    }
}
