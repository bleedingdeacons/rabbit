<?php

declare(strict_types=1);

namespace Rabbit\Core;

if (!defined('ABSPATH')) {
    exit;
}

use Psr\Container\ContainerInterface;
use Rabbit\Members\MemberMessenger;
use Unity\Members\Interfaces\MemberRepository;

/**
 * Register Rabbit's shared services into Unity's container.
 *
 * Rabbit deliberately ships no concrete binding for
 * {@see \Rabbit\Messaging\Interfaces\MessageService} — that's the
 * implementation plugin's responsibility (WhatsApp, etc.), bound on
 * `rabbit/loaded`. The service provider registers the things that
 * *are* Rabbit's responsibility: the high-level {@see MemberMessenger}
 * helper and the cross-plugin extension hook.
 *
 * {@see MemberMessenger} is registered as a lazy factory: it is given the
 * shared container (so it can resolve the bound driver and Scrutiny's
 * AuditLogger at call time) plus Unity's {@see MemberRepository}.
 */
final class RabbitServiceProvider
{
    public function register(ContainerInterface $container): void
    {
        // Unity's container exposes register($id, $factory); guard in case
        // a future/alternative container is supplied that doesn't.
        if (!method_exists($container, 'register')) {
            \Rabbit\Plugin::logError(
                'Container does not support register() bindings; Rabbit cannot register its services.',
                ['container_class' => get_class($container)]
            );
            return;
        }

        $container->register(MemberMessenger::class, function (ContainerInterface $c) {
            return new MemberMessenger(
                $c,
                $c->get(MemberRepository::class),
            );
        });

        /**
         * Fires while Rabbit is registering shared services, before
         * `rabbit/loaded`. Use this hook to register helpers (or to
         * decorate the MemberMessenger) that should be available before
         * any implementation plugin's service provider runs.
         *
         * @param ContainerInterface $container Shared dependency container
         */
        do_action('rabbit/register_services', $container);
    }
}
