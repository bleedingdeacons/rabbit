<?php

declare(strict_types=1);

namespace Rabbit\Messaging\Interfaces;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The common throwable type drivers raise on operational failure.
 *
 * Subclassing is encouraged — implementations may distinguish, e.g.,
 * an `AuthenticationFailedException` from a `RateLimitedException` so
 * the admin UI can render specific guidance. Catching `MessagingException`
 * at the call site catches everything driver-level without coupling the
 * caller to driver-specific subclasses.
 *
 * Network failures, provider rejections, and validation failures (e.g.
 * a message with no recipient passed to `send()`) all belong here, since
 * they're all "the message did not go out."
 */
class MessagingException extends \RuntimeException
{
}
