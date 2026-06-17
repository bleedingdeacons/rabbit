<?php

declare(strict_types=1);

namespace Rabbit\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Rabbit\Messaging\Models\Message;
use Rabbit\Messaging\Models\Recipient;

final class MessageTest extends TestCase
{
    public function test_text_factory(): void
    {
        $m = Message::text(Recipient::to('+447700900123', 'Anon', 1), 'Hello', ['member_id' => 1]);
        $this->assertTrue($m->isText());
        $this->assertFalse($m->isTemplate());
        $this->assertSame(Message::TYPE_TEXT, $m->getType());
        $this->assertSame('Hello', $m->getBody());
        $this->assertSame(1, $m->getTo()->getMemberId());
        $this->assertSame(['member_id' => 1], $m->getMeta());
    }

    public function test_template_factory(): void
    {
        $m = Message::template(
            Recipient::to('+447700900123'),
            'shift_reminder',
            'en_GB',
            ['1 hour', 'Tuesday']
        );
        $this->assertTrue($m->isTemplate());
        $this->assertSame('shift_reminder', $m->getTemplateName());
        $this->assertSame('en_GB', $m->getTemplateLanguage());
        $this->assertSame(['1 hour', 'Tuesday'], $m->getTemplateParams());
    }

    public function test_unknown_type_coerces_to_text(): void
    {
        $m = new Message(['to' => ['phone' => '+447700900123'], 'type' => 'carrier-pigeon']);
        $this->assertSame(Message::TYPE_TEXT, $m->getType());
    }

    public function test_template_params_are_stringified(): void
    {
        $m = Message::template(Recipient::to('+447700900123'), 't', 'en_GB', [1, 2.5, 'x']);
        $this->assertSame(['1', '2.5', 'x'], $m->getTemplateParams());
    }

    public function test_with_overrides_fields(): void
    {
        $m = Message::text(Recipient::to('+447700900123'), 'Hello');
        $m2 = $m->with(['body' => 'Goodbye']);
        $this->assertSame('Hello', $m->getBody());
        $this->assertSame('Goodbye', $m2->getBody());
    }

    public function test_array_round_trip(): void
    {
        $m = Message::template(Recipient::to('+447700900123', 'Anon', 9), 't', 'en_GB', ['a']);
        $copy = new Message($m->toArray());
        $this->assertEquals($m, $copy);
    }

    public function test_to_accepts_recipient_object(): void
    {
        $recipient = Recipient::to('+447700900123', 'Anon', 3);
        $m = new Message(['to' => $recipient, 'type' => 'text', 'body' => 'Hi']);
        $this->assertSame(3, $m->getTo()->getMemberId());
    }
}
