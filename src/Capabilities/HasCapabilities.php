<?php

declare(strict_types=1);

namespace Rabbit\Capabilities;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Shared role / capability helpers for member-messaging administration.
 *
 * The runtime permission-check API used by services and admin pages.
 * Role/capability *creation* lives in {@see CapabilityBootstrap}, which
 * the activation hook calls directly.
 *
 * Roles understood by Rabbit:
 *  - rabbit_operator — full control: manage connection + send.
 *  - rabbit_sender   — send messages; can't change settings.
 *  - rabbit_viewer   — read-only access to status / settings.
 */
trait HasCapabilities
{
    /**
     * @return array<int,string>
     */
    private static function allCapabilities(): array
    {
        return CapabilityBootstrap::allCapabilities();
    }

    protected function userIsOperator(int $userId = 0): bool
    {
        $user = $userId ? get_userdata($userId) : wp_get_current_user();
        return $user && $user->has_cap('rabbit_manage_messaging');
    }

    protected function userCanSend(int $userId = 0): bool
    {
        $user = $userId ? get_userdata($userId) : wp_get_current_user();
        return $user && $user->has_cap('rabbit_send_message');
    }

    protected function userCanView(int $userId = 0): bool
    {
        $user = $userId ? get_userdata($userId) : wp_get_current_user();
        return $user && $user->has_cap('rabbit_view_messaging');
    }
}
