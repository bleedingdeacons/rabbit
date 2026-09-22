<?php

declare(strict_types=1);

namespace Rabbit\Tests\Unit;

use Rabbit\Messaging\Models\Message;
use Rabbit\Messaging\Models\Recipient;

it('builds a text message from the factory', function () {
    $m = Message::text(Recipient::to('+447700900123', 'Anon', 1), 'Hello', ['member_id' => 1]);
    expect($m->isText())->toBeTrue()
        ->and($m->isTemplate())->toBeFalse()
        ->and($m->getType())->toBe(Message::TYPE_TEXT)
        ->and($m->getBody())->toBe('Hello')
        ->and($m->getTo()->getMemberId())->toBe(1)
        ->and($m->getMeta())->toBe(['member_id' => 1]);
});

it('builds a template message from the factory', function () {
    $m = Message::template(
        Recipient::to('+447700900123'),
        'shift_reminder',
        'en_GB',
        ['1 hour', 'Tuesday']
    );
    expect($m->isTemplate())->toBeTrue()
        ->and($m->getTemplateName())->toBe('shift_reminder')
        ->and($m->getTemplateLanguage())->toBe('en_GB')
        ->and($m->getTemplateParams())->toBe(['1 hour', 'Tuesday']);
});

it('coerces an unknown type to text', function () {
    $m = new Message(['to' => ['phone' => '+447700900123'], 'type' => 'carrier-pigeon']);
    expect($m->getType())->toBe(Message::TYPE_TEXT);
});

it('stringifies template params', function () {
    $m = Message::template(Recipient::to('+447700900123'), 't', 'en_GB', [1, 2.5, 'x']);
    expect($m->getTemplateParams())->toBe(['1', '2.5', 'x']);
});

it('overrides fields with with()', function () {
    $m = Message::text(Recipient::to('+447700900123'), 'Hello');
    $m2 = $m->with(['body' => 'Goodbye']);
    expect($m->getBody())->toBe('Hello')
        ->and($m2->getBody())->toBe('Goodbye');
});

it('survives an array round trip', function () {
    $m = Message::template(Recipient::to('+447700900123', 'Anon', 9), 't', 'en_GB', ['a']);
    $copy = new Message($m->toArray());
    expect($copy)->toEqual($m);
});

it('accepts a Recipient object for to', function () {
    $recipient = Recipient::to('+447700900123', 'Anon', 3);
    $m = new Message(['to' => $recipient, 'type' => 'text', 'body' => 'Hi']);
    expect($m->getTo()->getMemberId())->toBe(3);
});
