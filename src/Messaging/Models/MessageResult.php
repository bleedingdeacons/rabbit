<?php

declare(strict_types=1);

namespace Rabbit\Messaging\Models;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Immutable value object representing the outcome of a send.
 *
 * Drivers return one of these from {@see \Rabbit\Messaging\Interfaces\MessageService::send()}
 * on success. (Failures are thrown as
 * {@see \Rabbit\Messaging\Interfaces\MessagingException}, not returned
 * as a falsey result, so callers always know whether the message went
 * out.)
 *
 *  - `success`   whether the provider accepted the message.
 *  - `messageId` the provider's handle for the message (the WhatsApp
 *                Cloud API returns a `wamid.…`). Not personal data — it
 *                is a server-side message reference, safe to log/store.
 *  - `status`    a short provider/driver status string, e.g. 'accepted'.
 *  - `raw`       the decoded provider response, for diagnostics. Callers
 *                should not depend on its shape across drivers.
 */
final class MessageResult
{
    public readonly bool $success;
    public readonly string $messageId;
    public readonly string $status;
    /** @var array<string,mixed> */
    public readonly array $raw;

    /**
     * @param array<string,mixed> $raw
     */
    public function __construct(bool $success, string $messageId = '', string $status = '', array $raw = [])
    {
        $this->success = $success;
        $this->messageId = $messageId;
        $this->status = $status;
        $this->raw = $raw;
    }

    /**
     * The provider accepted the message.
     *
     * @param array<string,mixed> $raw
     */
    public static function accepted(string $messageId, array $raw = [], string $status = 'accepted'): self
    {
        return new self(true, $messageId, $status, $raw);
    }

    public function isSuccess(): bool
    {
        return $this->success;
    }

    public function getMessageId(): string
    {
        return $this->messageId;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    /** @return array<string,mixed> */
    public function getRaw(): array
    {
        return $this->raw;
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'success' => $this->success,
            'message_id' => $this->messageId,
            'status' => $this->status,
            'raw' => $this->raw,
        ];
    }
}
