<?php

declare(strict_types=1);

/**
 * PHPUnit bootstrap for Rabbit.
 *
 * Defines ABSPATH and a small set of WP function shims so Rabbit's
 * source files (which guard against direct access and call a handful of
 * WP utilities) load under PHPUnit without a real WordPress. Cross-plugin
 * interfaces Rabbit type-hints (Unity's Member/MemberRepository and
 * Scrutiny's AuditLogger) are stubbed here so the MemberMessenger unit
 * test can run in isolation.
 */

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}

// PSR-11 contracts (stubbed for testing — real installs use Unity/composer).
require_once __DIR__ . '/stubs/Psr/Container/ContainerExceptionInterface.php';
require_once __DIR__ . '/stubs/Psr/Container/NotFoundExceptionInterface.php';
require_once __DIR__ . '/stubs/Psr/Container/ContainerInterface.php';

// --- Rabbit source ---------------------------------------------------
$src = __DIR__ . '/../src';
require_once $src . '/Logger/HasLogger.php';
require_once $src . '/Messaging/Interfaces/MessagingException.php';
require_once $src . '/Messaging/Models/Recipient.php';
require_once $src . '/Messaging/Models/Message.php';
require_once $src . '/Messaging/Models/MessageResult.php';
require_once $src . '/Messaging/Interfaces/MessageService.php';
require_once $src . '/Messaging/AbstractMessageService.php';
require_once $src . '/Transport/Interfaces/TransportException.php';
require_once $src . '/Transport/Interfaces/HttpTransport.php';
require_once $src . '/Transport/Interfaces/HttpTransportFactory.php';

// --- Cross-plugin interface stubs --------------------------------------
// Minimal shapes of the Unity / Scrutiny contracts MemberMessenger
// type-hints. Real installs provide the genuine articles via their
// autoloaders; here we only need the symbols to exist with the members
// MemberMessenger actually touches.
if (!interface_exists('Unity\\Members\\Interfaces\\Member')) {
    eval('namespace Unity\\Members\\Interfaces; interface Member {
        public function getId(): int;
        public function getAnonymousName(): string;
        public function getMobileNumber(): string;
    }');
}
if (!interface_exists('Unity\\Members\\Interfaces\\MemberRepository')) {
    eval('namespace Unity\\Members\\Interfaces; interface MemberRepository {
        public function findById(int $id): ?Member;
    }');
}
if (!interface_exists('Scrutiny\\Audit\\Interfaces\\AuditLogger')) {
    eval('namespace Scrutiny\\Audit\\Interfaces; interface AuditLogger {
        const ENTITY_MEMBER = "member";
        public function log(string $action, string $entityType, int $entityId, string $fieldName, string $detail = ""): void;
        public function logBatch(string $action, string $entityType, int $entityId, array $fieldNames, string $detail = ""): void;
    }');
}

require_once $src . '/Members/MemberMessenger.php';

// --- WP HTTP API shims (for the WpHttpTransport tests) ------------------
require_once __DIR__ . '/Support/FakeWpHttp.php';

if (!function_exists('sanitize_key')) {
    function sanitize_key($key): string
    {
        return strtolower(preg_replace('/[^a-z0-9_\-]/', '', (string) $key) ?? '');
    }
}

if (!class_exists('WP_Error')) {
    class WP_Error
    {
        public function __construct(
            private string $code = '',
            private string $message = '',
        ) {
        }

        public function get_error_code(): string
        {
            return $this->code;
        }

        public function get_error_message(): string
        {
            return $this->message;
        }
    }
}

if (!class_exists('WP_Http_Cookie')) {
    class WP_Http_Cookie
    {
        public string $name = '';
        public string $value = '';

        /** @param array<string,mixed> $args */
        public function __construct(array $args = [])
        {
            $this->name  = (string) ($args['name'] ?? '');
            $this->value = (string) ($args['value'] ?? '');
        }
    }
}

if (!function_exists('is_wp_error')) {
    function is_wp_error($thing): bool
    {
        return $thing instanceof \WP_Error;
    }
}

if (!function_exists('wp_remote_request')) {
    function wp_remote_request(string $url, array $args = [])
    {
        return \Rabbit\Tests\Support\FakeWpHttp::dispatch($url, $args);
    }
}

if (!function_exists('wp_remote_retrieve_response_code')) {
    function wp_remote_retrieve_response_code($response)
    {
        if ($response instanceof \WP_Error) {
            return '';
        }
        return $response['response']['code'] ?? '';
    }
}

if (!function_exists('wp_remote_retrieve_body')) {
    function wp_remote_retrieve_body($response): string
    {
        if ($response instanceof \WP_Error) {
            return '';
        }
        return (string) ($response['body'] ?? '');
    }
}

if (!function_exists('wp_remote_retrieve_headers')) {
    function wp_remote_retrieve_headers($response)
    {
        if ($response instanceof \WP_Error) {
            return [];
        }
        return $response['headers'] ?? [];
    }
}

if (!function_exists('wp_remote_retrieve_cookies')) {
    function wp_remote_retrieve_cookies($response): array
    {
        if ($response instanceof \WP_Error) {
            return [];
        }
        return $response['cookies'] ?? [];
    }
}

require_once $src . '/Transport/WpHttpTransport.php';
require_once $src . '/Transport/WpHttpTransportFactory.php';
