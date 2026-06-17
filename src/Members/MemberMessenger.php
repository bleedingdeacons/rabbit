<?php

declare(strict_types=1);

namespace Rabbit\Members;

if (!defined('ABSPATH')) {
    exit;
}

use Psr\Container\ContainerInterface;
use Rabbit\Messaging\Interfaces\MessageService;
use Rabbit\Messaging\Interfaces\MessagingException;
use Rabbit\Messaging\Models\Message;
use Rabbit\Messaging\Models\MessageResult;
use Rabbit\Messaging\Models\Recipient;
use Scrutiny\Audit\Interfaces\AuditLogger;
use Unity\Members\Interfaces\Member;
use Unity\Members\Interfaces\MemberRepository;

/**
 * The headline entry point: send a message to a Unity member.
 *
 * This is the layer that ties the three plugins together. It:
 *  1. resolves a Unity {@see Member} (and reads their mobile number),
 *  2. builds a driver-agnostic {@see Message},
 *  3. dispatches it through whatever {@see MessageService} driver an
 *     implementation plugin (e.g. WhatsApp) has bound, and
 *  4. records a Scrutiny GDPR audit entry (action "message") so reading
 *     a member's mobile number to message them leaves an audit trail.
 *
 * Both the driver and the audit logger are resolved from the shared
 * container *lazily, per call* — the driver is bound on
 * `rabbit/loaded` (after Rabbit's own services register) and the
 * audit logger comes from Scrutiny, so resolving them at construction
 * time would be fragile. By the time anyone actually sends, both are in
 * place.
 *
 * Usage:
 *   rabbit()->get(MemberMessenger::class)
 *       ->sendTextToMember($memberId, 'Your shift starts in 1 hour.');
 */
final class MemberMessenger
{
    use \Rabbit\Logger\HasLogger;

    protected static function logChannel(): string
    {
        return 'rabbit';
    }

    /**
     * The Scrutiny audit action recorded for every member message.
     *
     * Defined here as a literal (rather than referencing a Scrutiny
     * constant) so Rabbit does not become coupled to a Scrutiny
     * version that may predate the matching ACTION_MESSAGE constant.
     * The value matches Scrutiny's lowercase action convention
     * ('view', 'call', …).
     */
    public const AUDIT_ACTION = 'message';

    /** The personal-data field whose access is being audited. */
    private const AUDIT_FIELD = 'mobile_number';

    public function __construct(
        private readonly ContainerInterface $container,
        private readonly MemberRepository $members,
    ) {
    }

    /**
     * Send a free-form text message to a member.
     *
     * @param array<string,mixed> $meta Optional caller bookkeeping (not sent).
     *
     * @throws MessagingException If the member is unknown, has no mobile
     *                            number, no driver is bound, or the
     *                            provider rejected the message.
     */
    public function sendTextToMember(int $memberId, string $body, array $meta = []): MessageResult
    {
        $member = $this->resolveMember($memberId);
        $message = Message::text(
            $this->recipientFor($member),
            $body,
            $this->withMemberMeta($member, $meta),
        );
        return $this->dispatch($message);
    }

    /**
     * Send a pre-approved template message to a member.
     *
     * @param array<int,string>   $params Ordered template body parameters.
     * @param array<string,mixed> $meta   Optional caller bookkeeping (not sent).
     *
     * @throws MessagingException
     */
    public function sendTemplateToMember(
        int $memberId,
        string $templateName,
        string $language,
        array $params = [],
        array $meta = []
    ): MessageResult {
        $member = $this->resolveMember($memberId);
        $message = Message::template(
            $this->recipientFor($member),
            $templateName,
            $language,
            $params,
            $this->withMemberMeta($member, $meta),
        );
        return $this->dispatch($message);
    }

    /**
     * Lower-level send for callers that already hold a {@see Member} and
     * a built {@see Message}. The message's recipient is rebuilt from the
     * member so the destination always matches the audited member rather
     * than trusting a caller-supplied number.
     *
     * @throws MessagingException
     */
    public function messageMember(Member $member, Message $message): MessageResult
    {
        $message = $message->with([
            'to' => $this->recipientFor($member)->toArray(),
            'meta' => $this->withMemberMeta($member, $message->getMeta()),
        ]);
        return $this->dispatch($message);
    }

    // -- internals --------------------------------------------------------

    /**
     * Resolve the bound driver, send, then write the audit entry.
     *
     * @throws MessagingException
     */
    private function dispatch(Message $message): MessageResult
    {
        if (!$this->container->has(MessageService::class)) {
            self::logError('Send attempted with no driver bound', [
                'member_id' => $message->getTo()->getMemberId(),
                'type' => $message->getType(),
            ]);
            throw new MessagingException(
                'No message driver is bound. Activate an implementation plugin (e.g. WhatsApp) to send messages.'
            );
        }

        /** @var MessageService $service */
        $service = $this->container->get(MessageService::class);

        self::logInfo('Sending message to member', [
            'member_id' => $message->getTo()->getMemberId(),
            'type' => $message->getType(),
            'to' => self::maskNumber($message->getTo()->getPhone()),
        ]);

        // Driver throws MessagingException on failure; we let it propagate
        // so the caller (and admin UI) sees the real reason.
        $result = $service->send($message);

        self::logInfo('Message sent to member', [
            'member_id' => $message->getTo()->getMemberId(),
            'type' => $message->getType(),
            'message_id' => $result->getMessageId(),
            'status' => $result->getStatus(),
        ]);

        $this->audit($message, $result);

        return $result;
    }

    /**
     * Write the Scrutiny audit entry for a sent member message.
     *
     * Records the *fact* that a member's mobile number was used to send a
     * message — action "message", entity "member" — with a non-PII detail
     * string (message type + provider message id; never the number or the
     * body). An audit-write failure is logged but never propagated: the
     * message has already gone out, and failing the caller at this point
     * would be misleading.
     */
    private function audit(Message $message, MessageResult $result): void
    {
        $recipient = $message->getTo();
        if (!$recipient->hasMember()) {
            // Ad-hoc send to a raw number — no member entity to scope the
            // audit entry to. Nothing to record here.
            return;
        }

        if (!$this->container->has(AuditLogger::class)) {
            self::logWarning('No AuditLogger bound; member message not audited', [
                'member_id' => $recipient->getMemberId(),
            ]);
            return;
        }

        try {
            /** @var AuditLogger $logger */
            $logger = $this->container->get(AuditLogger::class);

            $detail = sprintf(
                'Sent %s message via Rabbit%s%s',
                $message->getType(),
                $message->isTemplate() && $message->getTemplateName() !== ''
                    ? ' (template: ' . $message->getTemplateName() . ')'
                    : '',
                $result->getMessageId() !== ''
                    ? ' [' . $result->getMessageId() . ']'
                    : ''
            );

            $logger->log(
                self::AUDIT_ACTION,
                AuditLogger::ENTITY_MEMBER,
                $recipient->getMemberId(),
                self::AUDIT_FIELD,
                $detail
            );

            self::logDebug('Member message audited', [
                'member_id' => $recipient->getMemberId(),
                'action' => self::AUDIT_ACTION,
            ]);
        } catch (\Throwable $e) {
            // Never let an audit failure mask a successful send.
            self::logError('Failed to write member message audit entry', [
                'member_id' => $recipient->getMemberId(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Load a member by ID, asserting they exist and have a mobile number.
     *
     * @throws MessagingException
     */
    private function resolveMember(int $memberId): Member
    {
        $member = $this->members->findById($memberId);
        if ($member === null) {
            throw new MessagingException('No member found with ID ' . $memberId . '.');
        }
        if (trim($member->getMobileNumber()) === '') {
            throw new MessagingException(
                'Member ' . $memberId . ' has no mobile number on file; cannot send a message.'
            );
        }
        return $member;
    }

    private function recipientFor(Member $member): Recipient
    {
        return Recipient::to(
            $member->getMobileNumber(),
            $member->getAnonymousName(),
            $member->getId(),
        );
    }

    /**
     * Stamp the originating member ID into the message meta so a driver
     * or decorator that wants it (e.g. for correlation) can read it.
     *
     * @param array<string,mixed> $meta
     * @return array<string,mixed>
     */
    private function withMemberMeta(Member $member, array $meta): array
    {
        return array_merge(['member_id' => $member->getId()], $meta);
    }

    /**
     * Mask a phone number for logging: keep the last two digits, mask the
     * rest. The number is personal data and must never be logged in full.
     */
    private static function maskNumber(string $raw): string
    {
        $digits = preg_replace('/\D/', '', $raw) ?? '';
        $len = strlen($digits);
        if ($len === 0) {
            return '(empty)';
        }
        if ($len <= 2) {
            return str_repeat('*', $len);
        }
        return str_repeat('*', $len - 2) . substr($digits, -2) . ' (' . $len . ' digits)';
    }
}
