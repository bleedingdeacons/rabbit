<?php

declare(strict_types=1);

namespace Rabbit\Tests\Unit;

use Rabbit\Messaging\AbstractMessageService;
use Rabbit\Messaging\Interfaces\MessagingException;
use Rabbit\Messaging\Models\Message;
use Rabbit\Messaging\Models\MessageResult;
use Rabbit\Messaging\Models\Recipient;

/**
 * Test-only subclass that exposes the protected validation method.
 *
 * The abstract is meant to be extended by drivers; tests are the
 * cleanest way to exercise its protected surface without making those
 * methods public on every concrete driver.
 */
final class TestableMessageService extends AbstractMessageService
{
    public function send(Message $message): MessageResult
    {
        return MessageResult::accepted('test');
    }

    public function testConnection(): bool
    {
        return true;
    }

    public function exposeValidate(Message $message): void
    {
        $this->validateMessage($message);
    }

    public static function exposeNormalise(string $raw): string
    {
        return self::normaliseNumber($raw);
    }
}

describe('validateMessage', function () {
    it('throws for a message without a recipient', function () {
        $service = new TestableMessageService();
        $service->exposeValidate(Message::text(Recipient::to(''), 'Hello'));
    })->throws(MessagingException::class, 'no recipient');

    it('throws for an implausible number', function () {
        $service = new TestableMessageService();
        $service->exposeValidate(Message::text(Recipient::to('12'), 'Hello'));
    })->throws(MessagingException::class);

    it('throws for an empty text body', function () {
        $service = new TestableMessageService();
        $service->exposeValidate(Message::text(Recipient::to('+447700900123'), '   '));
    })->throws(MessagingException::class, 'non-empty body');

    it('passes a valid text message', function () {
        $service = new TestableMessageService();

        expect(fn () => $service->exposeValidate(Message::text(Recipient::to('+447700900123'), 'Hello')))
            ->not->toThrow(MessagingException::class); // didn't throw
    });

    it('throws for a template without a name', function () {
        $service = new TestableMessageService();
        $service->exposeValidate(Message::template(Recipient::to('+447700900123'), '', 'en_GB'));
    })->throws(MessagingException::class, 'template name');

    it('throws for a template without a language', function () {
        $service = new TestableMessageService();
        $service->exposeValidate(Message::template(Recipient::to('+447700900123'), 'tmpl', ''));
    })->throws(MessagingException::class, 'language code');

    it('passes a valid template message', function () {
        $service = new TestableMessageService();

        expect(fn () => $service->exposeValidate(
            Message::template(Recipient::to('+447700900123'), 'tmpl', 'en_GB', ['x'])
        ))->not->toThrow(MessagingException::class);
    });
});

it('normalises a number', function (string $input, string $expected) {
    expect(TestableMessageService::exposeNormalise($input))->toBe($expected);
})->with([
    'plus e164'      => ['+44 7700 900123', '+447700900123'],
    'bare digits'    => ['447700900123', '447700900123'],
    'decorated'      => ['(07700) 900-123', '07700900123'],
    'too short'      => ['12345', ''],
    'empty'          => ['', ''],
    'just plus'      => ['+', ''],
    'double plus'    => ['+44+7700', ''],
]);
