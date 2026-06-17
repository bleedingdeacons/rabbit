<?php

declare(strict_types=1);

namespace Rabbit\Messaging\Models;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Immutable value object representing who a message is being sent to.
 *
 *  - `phone`    the destination, as supplied by the caller. Drivers
 *               normalise it for their provider (the WhatsApp driver,
 *               for instance, strips the leading `+`). Required.
 *  - `name`     a human label for logs and the admin UI — typically a
 *               member's anonymous name. Never the message content.
 *  - `memberId` the Unity member ID this recipient corresponds to, or
 *               0 for an ad-hoc send (e.g. a "send test" to a raw
 *               number). Used to key the Scrutiny audit entry.
 *
 * Note this model deliberately does NOT know about Unity's Member type —
 * the member → recipient mapping lives in
 * {@see \Rabbit\Members\MemberMessenger} so this value object stays a
 * pure, dependency-free data holder (and unit-testable without Unity).
 */
final class Recipient
{
    public readonly string $phone;
    public readonly string $name;
    public readonly int $memberId;

    /**
     * @param array<string,mixed> $data
     */
    public function __construct(array $data)
    {
        $this->phone = trim((string) ($data['phone'] ?? ''));
        $this->name = (string) ($data['name'] ?? '');
        $this->memberId = (int) ($data['member_id'] ?? 0);
    }

    /**
     * Convenience constructor for the common "send to this number" case.
     */
    public static function to(string $phone, string $name = '', int $memberId = 0): self
    {
        return new self([
            'phone' => $phone,
            'name' => $name,
            'member_id' => $memberId,
        ]);
    }

    public function getPhone(): string
    {
        return $this->phone;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getMemberId(): int
    {
        return $this->memberId;
    }

    /**
     * Whether this recipient corresponds to a real Unity member (rather
     * than an ad-hoc raw number). Used to decide whether to write a
     * member-scoped audit entry.
     */
    public function hasMember(): bool
    {
        return $this->memberId > 0;
    }

    /**
     * Human-readable summary for log lines and admin tables. The phone
     * number is the personal data, so callers that log this should mask
     * it; `describe()` itself returns name + number for UI rendering.
     */
    public function describe(): string
    {
        if ($this->name === '') {
            return $this->phone;
        }
        if ($this->phone === '') {
            return $this->name;
        }
        return $this->name . ' <' . $this->phone . '>';
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'phone' => $this->phone,
            'name' => $this->name,
            'member_id' => $this->memberId,
        ];
    }
}
