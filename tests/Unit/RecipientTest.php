<?php

declare(strict_types=1);

namespace Rabbit\Tests\Unit;

use Rabbit\Messaging\Models\Recipient;

it('builds a recipient with to()', function () {
    $r = Recipient::to('+44 7700 900123', 'Anon A', 42);
    expect($r->getPhone())->toBe('+44 7700 900123')
        ->and($r->getName())->toBe('Anon A')
        ->and($r->getMemberId())->toBe(42)
        ->and($r->hasMember())->toBeTrue();
});

it('gives an ad hoc recipient no member', function () {
    $r = Recipient::to('+447700900123');
    expect($r->getMemberId())->toBe(0)
        ->and($r->hasMember())->toBeFalse();
});

it('describes itself by combining name and number', function () {
    expect(Recipient::to('+447700900123', 'Anon A')->describe())->toBe('Anon A <+447700900123>')
        ->and(Recipient::to('+447700900123')->describe())->toBe('+447700900123')
        ->and(Recipient::to('', 'Anon A')->describe())->toBe('Anon A');
});

it('survives an array round trip', function () {
    $r = Recipient::to('+447700900123', 'Anon A', 7);
    $copy = new Recipient($r->toArray());
    expect($copy)->toEqual($r);
});

it('trims the phone number', function () {
    $r = new Recipient(['phone' => '  +447700900123  ']);
    expect($r->getPhone())->toBe('+447700900123');
});
