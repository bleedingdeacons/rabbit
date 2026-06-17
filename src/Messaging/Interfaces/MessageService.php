<?php

declare(strict_types=1);

namespace Rabbit\Messaging\Interfaces;

if (!defined('ABSPATH')) {
    exit;
}

use Rabbit\Messaging\Models\Message;
use Rabbit\Messaging\Models\MessageResult;

/**
 * The contract every message driver implements.
 *
 * The contract is intentionally narrow:
 *  - Send one message.
 *  - Check that the connection / credentials actually work.
 *
 * Anything driver-specific — credentials, payload encoding, template
 * registration, rate-limit handling, vendor options — belongs in the
 * implementation, not in this interface.
 *
 * Drivers MUST throw {@see MessagingException} (or a subclass) for any
 * operational failure rather than returning a falsey value, so callers
 * always know whether the message was sent or whether it threw.
 *
 * Note this contract sends to a {@see Message} (which carries a raw
 * recipient phone number); turning a *Unity member* into a Message —
 * and writing the Scrutiny audit entry — is the job of the higher-level
 * {@see \Rabbit\Members\MemberMessenger}, not of individual drivers.
 */
interface MessageService
{
    /**
     * Send a single message.
     *
     * @return MessageResult The provider's accepted result (carries the
     *                       provider message id).
     *
     * @throws MessagingException If the message is invalid, the provider
     *                            rejected it, or it could not be reached.
     */
    public function send(Message $message): MessageResult;

    /**
     * Verify the driver can reach the provider with its configured
     * credentials.
     *
     * Drivers should return true only after a real round-trip — not just
     * "credentials are non-empty". Callers use this from the admin UI's
     * "test connection" button, so it needs to fail loud when the
     * configuration is wrong rather than appearing healthy.
     *
     * @throws MessagingException
     */
    public function testConnection(): bool;
}
