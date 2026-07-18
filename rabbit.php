<?php

declare(strict_types=1);

/**
 * Plugin Name: Rabbit
 * Description: Framework for sending outbound messages to Unity members. Defines the contracts (MessageService, models, transport) and a high-level MemberMessenger helper that turns a Unity member into a sent message; an implementation plugin (e.g. WhatsApp) binds a concrete driver. Ships no driver of its own — Rabbit alone does nothing visible until an implementation plugin is active. Requires Unity for member data and Scrutiny for GDPR audit logging.
 * Version: 1.0.1
 * Requires at least: 6.1
 * Requires PHP: 8.1
 * Requires Plugins: unity, scrutiny
 * GitHub Plugin URI: https://github.com/thebleedingdeacons/rabbit
 * GitHub Branch: main
 * Author: The Bleeding Deacons
 * Author URI: https://github.com/bleedingdeacons/rabbit
 * Contact: thebleedingdeacons@gmail.com
 * License: MIT (Modified)
 * Text Domain: rabbit
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Kill switch.
 *
 * Set `define('RABBIT_KILL', true);` in wp-config.php to deactivate
 * Rabbit without removing it from the active plugins list. Mirrors
 * Beacon/Stalwart — when enabled, Rabbit short-circuits here and
 * `rabbit/loaded` never fires, so the WhatsApp driver (and any other
 * downstream implementations) stand down too.
 */
if (defined('RABBIT_KILL') && RABBIT_KILL === true) {
    if (is_admin()) {
        add_action('admin_notices', function () {
            echo '<div class="notice notice-warning"><p>'
                . '<strong>Rabbit:</strong> Plugin is disabled via the '
                . '<code>RABBIT_KILL</code> kill switch in <code>wp-config.php</code>.'
                . '</p></div>';
        });
    }
    return;
}

// Define plugin constants
if (!function_exists('get_plugin_data')) {
    if (file_exists(ABSPATH . 'wp-admin/includes/plugin.php')) {
        require_once(ABSPATH . 'wp-admin/includes/plugin.php');
    }
}

$rabbit_plugin_data = get_plugin_data(__FILE__, false, false);
define('RABBIT_VERSION', $rabbit_plugin_data['Version']);
define('RABBIT_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('RABBIT_PLUGIN_URL', plugin_dir_url(__FILE__));
define('RABBIT_PLUGIN_FILE', __FILE__);

// Load Composer autoloader if present.
$rabbit_autoloader = RABBIT_PLUGIN_DIR . 'vendor/autoload.php';
if (file_exists($rabbit_autoloader)) {
    require_once $rabbit_autoloader;
}

// Fallback PSR-4 autoloader for the Rabbit namespace. Lets the plugin
// run on a fresh deployment before `composer install` has been executed.
// (The Psr\Container interfaces this plugin type-hints are provided by
// Unity, which loads first.)
spl_autoload_register(function ($class) {
    $prefix = 'Rabbit\\';
    $base_dir = RABBIT_PLUGIN_DIR . 'src/';

    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relative_class = substr($class, $len);
    $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';

    if (file_exists($file)) {
        require $file;
    }
});

/**
 * Resolve a service out of Rabbit's container.
 *
 * Rabbit uses Unity's shared container (it is registered there on
 * `unity/loaded`), so this returns the same container Unity, Scrutiny,
 * Reach, etc. all share. The headline entry point is:
 *
 *   rabbit()->get(\Rabbit\Members\MemberMessenger::class)
 *       ->sendTextToMember($memberId, $text);
 *
 * @return \Psr\Container\ContainerInterface
 * @throws \RuntimeException If Rabbit is not initialised yet.
 */
function rabbit(): \Psr\Container\ContainerInterface {
    return \Rabbit\Plugin::getContainer();
}

// Boot after Unity is loaded. Unity fires `unity/loaded` from
// `plugins_loaded` with its shared container; Rabbit registers its
// contracts/services into that container and then fires
// `rabbit/loaded` so implementation plugins (WhatsApp, …) can bind
// their concrete drivers.
add_action('unity/loaded', function ($container) {
    try {
        // Scrutiny provides the AuditLogger used to record one audit
        // entry per message sent to a member (action: "message"). Sending
        // a message reads a member's mobile number — personal data — so we
        // refuse to run without the audit trail rather than silently lose
        // it. Fail loud at init time, exactly as Reach does.
        if (!function_exists('scrutiny')) {
            throw new \Exception('Scrutiny plugin is required but not active. Please install and activate Scrutiny before using Rabbit (it provides the GDPR audit log).');
        }

        if (!class_exists('Rabbit\\Plugin')) {
            throw new \Exception('Rabbit\\Plugin class not found. Check that Plugin.php exists in the src/ directory.');
        }

        \Rabbit\Plugin::init($container);

        /**
         * Fires after Rabbit has registered its contracts with the
         * container. Implementation plugins (WhatsApp, etc.) hook this to
         * bind their concrete drivers.
         *
         * @param \Psr\Container\ContainerInterface $container The shared dependency container
         */
        do_action('rabbit/loaded', \Rabbit\Plugin::getContainer());

    } catch (\Exception $e) {
        function_exists('wp_log')
            ? wp_log('rabbit')->error('Rabbit Plugin Initialisation Error: ' . $e->getMessage(), ['exception' => $e->getMessage(), 'trace' => $e->getTraceAsString()])
            : error_log('Rabbit Plugin Initialisation Error: ' . $e->getMessage());

        if (is_admin()) {
            add_action('admin_notices', function () use ($e) {
                echo '<div class="notice notice-error is-dismissible"><p><strong>Rabbit Plugin Error:</strong> ' . esc_html($e->getMessage()) . '</p></div>';
            });
        }
    } catch (\Throwable $e) {
        function_exists('wp_log')
            ? wp_log('rabbit')->critical('Rabbit Plugin Fatal Error: ' . $e->getMessage(), ['exception' => $e->getMessage(), 'trace' => $e->getTraceAsString()])
            : error_log('Rabbit Plugin Fatal Error: ' . $e->getMessage());
    }
}, 10);

// Surface a notice if a required plugin is not available, so an operator
// isn't left guessing why Rabbit did nothing.
add_action('plugins_loaded', function () {
    if (!class_exists('Unity\\Plugin')) {
        add_action('admin_notices', function () {
            echo '<div class="notice notice-error"><p>';
            echo '<strong>' . esc_html__('Rabbit', 'rabbit') . ':</strong> ';
            echo esc_html__('This plugin requires the Unity plugin to be installed and activated.', 'rabbit');
            echo '</p></div>';
        });
    } elseif (!function_exists('scrutiny')) {
        add_action('admin_notices', function () {
            echo '<div class="notice notice-error"><p>';
            echo '<strong>' . esc_html__('Rabbit', 'rabbit') . ':</strong> ';
            echo esc_html__('This plugin requires the Scrutiny plugin to be installed and activated for GDPR audit logging.', 'rabbit');
            echo '</p></div>';
        });
    }
}, 20);

// Surface a notice if no implementation plugin has bound a driver.
// We can't check this until after init because implementations bind on
// `rabbit/loaded`, which fires during `unity/loaded` (itself fired from
// `plugins_loaded`); by `admin_notices` that sequence has completed.
add_action('admin_notices', function () {
    if (!did_action('rabbit/loaded')) {
        return;
    }
    if (!\Rabbit\Plugin::isInitialized()) {
        return;
    }
    if (\Rabbit\Plugin::hasDriver()) {
        return;
    }
    echo '<div class="notice notice-warning is-dismissible"><p>'
        . '<strong>Rabbit:</strong> No message driver is bound. Install and activate an implementation plugin (e.g. <em>WhatsApp</em>) to wire Rabbit up to a real provider.'
        . '</p></div>';
});

// Activation: register capabilities.
register_activation_hook(__FILE__, function () {
    require_once RABBIT_PLUGIN_DIR . 'src/Capabilities/CapabilityBootstrap.php';
    \Rabbit\Capabilities\CapabilityBootstrap::register();
});

register_deactivation_hook(__FILE__, function () {
    require_once RABBIT_PLUGIN_DIR . 'src/Capabilities/CapabilityBootstrap.php';
    \Rabbit\Capabilities\CapabilityBootstrap::remove();
});
