<?php

/**
 * Fired when Rabbit is uninstalled.
 *
 * Only removes capabilities. The provider connection settings and any
 * driver-specific data are owned by the implementation plugin (WhatsApp,
 * etc.) and cleaned up by its own uninstaller. Scrutiny audit entries
 * are owned by Scrutiny and intentionally preserved.
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

require_once __DIR__ . '/src/Capabilities/CapabilityBootstrap.php';

\Rabbit\Capabilities\CapabilityBootstrap::remove();
