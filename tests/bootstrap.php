<?php

declare(strict_types=1);

/**
 * PHPUnit bootstrap for Rabbit.
 *
 * WordPress stand-ins come from bleedingdeacons/wp-mocks, shared across the
 * plugin suite. Its bootstrap loads Patchwork before anything patchable, so
 * anything below that defines WordPress functions of its own must stay after
 * the Bootstrap::load() call, not before it.
 *
 * Not loaded here: the `sentinel` stub group. Rabbit\Logger\HasLogger is
 * written to no-op when wp_log() is absent — the shared logger mu-plugin is
 * Sentinel's, and Rabbit does not depend on it — and that is the branch these
 * tests run.
 *
 * What is not WordPress still has to be arranged here: the cross-plugin
 * interfaces Rabbit type-hints (Unity's Member/MemberRepository and Scrutiny's
 * AuditLogger) belong to sibling plugins that are not installed in the test
 * run, so MemberMessenger gets minimal stand-ins for them.
 */

use BleedingDeacons\WpMocks\Bootstrap;
use BleedingDeacons\WpMocks\WpState;

require_once __DIR__ . '/../vendor/autoload.php';

Bootstrap::load(['wordpress']);

// Makes plugins_url()/plugin_dir_url() answer with Rabbit's own path.
WpState::$pluginSlug = 'rabbit';

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}

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

// The WP HTTP API these two reach for is stubbed by wp-mocks, backed by
// Doubles\FakeWpHttp — no local shim needed to load them.
require_once $src . '/Transport/WpHttpTransport.php';
require_once $src . '/Transport/WpHttpTransportFactory.php';
