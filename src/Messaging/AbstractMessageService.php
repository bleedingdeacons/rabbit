<?php

declare(strict_types=1);

namespace Rabbit\Messaging;

if (!defined('ABSPATH')) {
    exit;
}

use Rabbit\Messaging\Interfaces\MessageService;
use Rabbit\Messaging\Interfaces\MessagingException;
use Rabbit\Messaging\Models\Message;

/**
 * Shared helper logic any concrete MessageService can extend.
 *
 * Two things consistently want sharing across drivers:
 *
 *  1. Validating a {@see Message} before it goes to the provider. A
 *     message with no recipient, an implausible number, an empty text
 *     body, or a template missing its name/language should fail at this
 *     boundary — letting the provider reject it produces a worse error
 *     and wastes a round-trip.
 *
 *  2. E.164-light normalisation of the recipient number. We don't ship a
 *     full E.164 parser (that's a libphonenumber concern), but we strip
 *     decorative characters and reject obviously-bad input so the driver
 *     gets clean digits.
 *
 * The class doesn't implement the contract itself — concrete drivers
 * still define the provider-facing methods. It just gives them a place
 * to stand.
 */
abstract class AbstractMessageService implements MessageService
{
    use \Rabbit\Logger\HasLogger;

    /**
     * Validate a message before it leaves the driver. Throws on the
     * first problem rather than collecting all of them — the admin UI
     * shows one issue at a time, and validating in order means the error
     * message points at the first thing the operator needs to fix.
     *
     * @throws MessagingException
     */
    protected function validateMessage(Message $message): void
    {
        $recipient = $message->getTo();
        if ($recipient->getPhone() === '') {
            throw new MessagingException(
                'Message has no recipient. A destination number is required.'
            );
        }
        if (self::normaliseNumber($recipient->getPhone()) === '') {
            throw new MessagingException(
                'Recipient "' . $recipient->getPhone() . '" does not look like a phone number.'
            );
        }

        switch ($message->getType()) {
            case Message::TYPE_TEXT:
                if (trim($message->getBody()) === '') {
                    throw new MessagingException('A text message needs a non-empty body.');
                }
                break;

            case Message::TYPE_TEMPLATE:
                if (trim($message->getTemplateName()) === '') {
                    throw new MessagingException('A template message needs a template name.');
                }
                if (trim($message->getTemplateLanguage()) === '') {
                    throw new MessagingException('A template message needs a language code (e.g. en_GB).');
                }
                break;

            default:
                throw new MessagingException('Unknown message type "' . $message->getType() . '".');
        }

        self::logDebug('Message validated', [
            'type' => $message->getType(),
            'member_id' => $recipient->getMemberId(),
            'to' => self::maskNumber($recipient->getPhone()),
        ]);
    }

    /**
     * Trim a phone-number-shaped string to digits with an optional
     * leading `+`. Returns '' if it doesn't look like a phone number at
     * all. We accept both `+`-prefixed E.164 and bare digit strings —
     * different providers want different forms, and forcing a canonical
     * shape here would reject input a provider is happy with.
     */
    protected static function normaliseNumber(string $raw): string
    {
        // Strip everything except digits and a leading `+`. Spaces,
        // dashes, parentheses, dots — all decorative.
        $cleaned = preg_replace('/[^\d+]/', '', $raw) ?? '';
        if ($cleaned === '' || $cleaned === '+') {
            return '';
        }
        // A `+` only makes sense at the start.
        if (str_contains(substr($cleaned, 1), '+')) {
            return '';
        }
        // Need a plausible minimum of digits. International numbers are
        // at least ~7 digits; anything much shorter is a parse error.
        $digits = preg_replace('/\D/', '', $cleaned) ?? '';
        if (strlen($digits) < 6) {
            return '';
        }
        return $cleaned;
    }

    /**
     * Mask a phone number for logging: keep the last two digits, mask
     * the rest. The number is personal data, so it must never be logged
     * in full.
     */
    protected static function maskNumber(string $raw): string
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
