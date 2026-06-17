<?php

declare(strict_types=1);

namespace Rabbit;

if (!defined('ABSPATH')) {
    exit;
}

use Psr\Container\ContainerInterface;
use RuntimeException;
use Rabbit\Core\RabbitServiceProvider;
use Rabbit\Messaging\Interfaces\MessageService;

/**
 * Main Rabbit Plugin Class.
 *
 * Rabbit is the *contracts* plugin for outbound member messaging. It
 * boots on `unity/loaded`, registers its interfaces and shared services
 * into Unity's container, and fires `rabbit/loaded` so implementation
 * plugins (WhatsApp, etc.) can bind their concrete drivers.
 *
 * Rabbit ships no concrete {@see MessageService} — that's the
 * implementation plugin's responsibility. If `rabbit/loaded` fires and
 * no driver is bound by the time someone tries to send, the resolution
 * fails loudly (with a clear MessagingException) so misconfiguration
 * surfaces fast rather than silently dropping messages.
 *
 * Unlike Beacon, Rabbit does not carry its own container: its whole
 * reason for existing is to message *Unity members*, so it hard-requires
 * Unity and simply adopts Unity's shared container — the same one
 * Scrutiny, Reach, Steward, etc. register against.
 */
class Plugin
{
    use \Rabbit\Logger\HasLogger;

    protected static function logChannel(): string
    {
        return 'rabbit';
    }

    private static ?ContainerInterface $container = null;
    private static bool $initialized = false;

    /**
     * Initialise Rabbit against Unity's shared container.
     *
     * Idempotent — subsequent calls are no-ops, which matters because
     * `unity/loaded` can in theory fire more than once during certain
     * test-harness setups.
     */
    public static function init(ContainerInterface $unityContainer): void
    {
        if (self::$initialized) {
            return;
        }

        self::$container = $unityContainer;

        (new RabbitServiceProvider())->register($unityContainer);

        self::$initialized = true;

        self::logDebug('Initialised', ['version' => defined('RABBIT_VERSION') ? RABBIT_VERSION : 'unknown']);
    }

    /**
     * Get the shared container.
     *
     * @throws RuntimeException If the plugin hasn't booted yet.
     */
    public static function getContainer(): ContainerInterface
    {
        if (self::$container === null) {
            throw new RuntimeException('Rabbit Plugin not initialised — wait for the rabbit/loaded action.');
        }
        return self::$container;
    }

    /**
     * Whether Rabbit has finished initialising.
     */
    public static function isInitialized(): bool
    {
        return self::$initialized;
    }

    /**
     * Whether an implementation plugin has bound a concrete driver to
     * the MessageService contract. Used by the admin notice to decide
     * whether to nag the operator that Rabbit is sitting idle.
     */
    public static function hasDriver(): bool
    {
        if (self::$container === null) {
            return false;
        }
        return self::$container->has(MessageService::class);
    }
}
