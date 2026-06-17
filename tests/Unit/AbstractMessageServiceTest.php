<?php

declare(strict_types=1);

namespace Rabbit\Tests\Unit;

use PHPUnit\Framework\TestCase;
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

final class AbstractMessageServiceTest extends TestCase
{
    public function test_message_without_recipient_throws(): void
    {
        $service = new TestableMessageService();
        $this->expectException(MessagingException::class);
        $this->expectExceptionMessage('no recipient');
        $service->exposeValidate(Message::text(Recipient::to(''), 'Hello'));
    }

    public function test_implausible_number_throws(): void
    {
        $service = new TestableMessageService();
        $this->expectException(MessagingException::class);
        $service->exposeValidate(Message::text(Recipient::to('12'), 'Hello'));
    }

    public function test_empty_text_body_throws(): void
    {
        $service = new TestableMessageService();
        $this->expectException(MessagingException::class);
        $this->expectExceptionMessage('non-empty body');
        $service->exposeValidate(Message::text(Recipient::to('+447700900123'), '   '));
    }

    public function test_valid_text_passes(): void
    {
        $service = new TestableMessageService();
        $service->exposeValidate(Message::text(Recipient::to('+447700900123'), 'Hello'));
        $this->assertTrue(true); // didn't throw
    }

    public function test_template_without_name_throws(): void
    {
        $service = new TestableMessageService();
        $this->expectException(MessagingException::class);
        $this->expectExceptionMessage('template name');
        $service->exposeValidate(Message::template(Recipient::to('+447700900123'), '', 'en_GB'));
    }

    public function test_template_without_language_throws(): void
    {
        $service = new TestableMessageService();
        $this->expectException(MessagingException::class);
        $this->expectExceptionMessage('language code');
        $service->exposeValidate(Message::template(Recipient::to('+447700900123'), 'tmpl', ''));
    }

    public function test_valid_template_passes(): void
    {
        $service = new TestableMessageService();
        $service->exposeValidate(Message::template(Recipient::to('+447700900123'), 'tmpl', 'en_GB', ['x']));
        $this->assertTrue(true);
    }

    /**
     * @dataProvider numberProvider
     */
    public function test_normalise_number(string $input, string $expected): void
    {
        $this->assertSame($expected, TestableMessageService::exposeNormalise($input));
    }

    /** @return array<string,array{0:string,1:string}> */
    public static function numberProvider(): array
    {
        return [
            'plus e164'      => ['+44 7700 900123', '+447700900123'],
            'bare digits'    => ['447700900123', '447700900123'],
            'decorated'      => ['(07700) 900-123', '07700900123'],
            'too short'      => ['12345', ''],
            'empty'          => ['', ''],
            'just plus'      => ['+', ''],
            'double plus'    => ['+44+7700', ''],
        ];
    }
}
