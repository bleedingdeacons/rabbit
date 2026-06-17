<?php

declare(strict_types=1);

namespace Rabbit\Capabilities;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Static bootstrap helper for activation / deactivation.
 *
 * Separated from HasCapabilities so PSR-4 autoloading resolves the
 * class. The activation hook in rabbit.php `require_once`s this file
 * directly because the autoloader may not yet be registered when the
 * activation callback fires on a fresh install.
 *
 * Roles created by `register()`:
 *  - rabbit_operator — full control: manage the connection settings
 *                         and send messages.
 *  - rabbit_sender   — day-to-day sending: send messages to members,
 *                         but can't change connection settings.
 *  - rabbit_viewer   — read-only: view messaging status / settings.
 *
 * `register()` also layers the full capability set onto the
 * administrator role, so admins inherit everything by default.
 */
final class CapabilityBootstrap
{
    public const ROLE_OPERATOR = 'rabbit_operator';
    public const ROLE_SENDER = 'rabbit_sender';
    public const ROLE_VIEWER = 'rabbit_viewer';

    /**
     * Create the Rabbit roles, grant each its capability set, and
     * layer the full superset onto the administrator role. Idempotent.
     */
    public static function register(): void
    {
        foreach (self::roleCapabilities() as $roleSlug => $caps) {
            $capMap = array_fill_keys($caps, true);

            // add_role() returns null if the role already exists, so
            // we follow up with get_role()->add_cap() to top up any
            // caps that may have been added in a later release.
            add_role($roleSlug, self::roleDisplayName($roleSlug), $capMap);

            $role = get_role($roleSlug);
            if ($role) {
                foreach ($caps as $cap) {
                    if (!$role->has_cap($cap)) {
                        $role->add_cap($cap);
                    }
                }
            }
        }

        $admin = get_role('administrator');
        if ($admin) {
            foreach (self::allCapabilities() as $cap) {
                $admin->add_cap($cap);
            }
        }
    }

    /**
     * Strip the capabilities from every role and remove the custom
     * roles. Call on deactivation/uninstall.
     */
    public static function remove(): void
    {
        $roles = wp_roles();
        foreach ($roles->roles as $slug => $_) {
            $role = get_role($slug);
            if ($role) {
                foreach (self::allCapabilities() as $cap) {
                    $role->remove_cap($cap);
                }
            }
        }

        foreach (array_keys(self::roleCapabilities()) as $roleSlug) {
            remove_role($roleSlug);
        }
    }

    /**
     * @return array<int,string>
     */
    public static function allCapabilities(): array
    {
        return [
            'rabbit_manage_messaging',
            'rabbit_send_message',
            'rabbit_view_messaging',
        ];
    }

    /**
     * Map of role slug → array of capability slugs the role gets.
     *
     * @return array<string,array<int,string>>
     */
    public static function roleCapabilities(): array
    {
        return [
            self::ROLE_OPERATOR => [
                'read',
                'rabbit_manage_messaging',
                'rabbit_send_message',
                'rabbit_view_messaging',
            ],
            self::ROLE_SENDER => [
                // Can send messages and view status, but can't change the
                // provider connection settings.
                'read',
                'rabbit_send_message',
                'rabbit_view_messaging',
            ],
            self::ROLE_VIEWER => [
                // Read-only access to status / settings.
                'read',
                'rabbit_view_messaging',
            ],
        ];
    }

    private static function roleDisplayName(string $slug): string
    {
        return match ($slug) {
            self::ROLE_OPERATOR => __('Messaging Operator', 'rabbit'),
            self::ROLE_SENDER => __('Messaging Sender', 'rabbit'),
            self::ROLE_VIEWER => __('Messaging Viewer', 'rabbit'),
            default => ucwords(str_replace('_', ' ', $slug)),
        };
    }
}
