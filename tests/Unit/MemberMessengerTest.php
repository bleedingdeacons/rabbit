<?php

declare(strict_types=1);

namespace Rabbit\Tests\Unit;

use Rabbit\Members\MemberMessenger;
use Rabbit\Messaging\Interfaces\MessageService;
use Rabbit\Messaging\Interfaces\MessagingException;
use Rabbit\Messaging\Models\Message;
use Rabbit\Messaging\Models\MessageResult;
use Scrutiny\Audit\Interfaces\AuditLogger;
use Unity\Testing\Doubles\FakeContainer;
use Unity\Testing\Doubles\InMemoryMemberRepository;
use Unity\Testing\Doubles\MemberStub;
use Scrutiny\Testing\Doubles\SpyAuditLogger;

final class CapturingMessageService implements MessageService
{
    public ?Message $sent = null;

    public function send(Message $message): MessageResult
    {
        $this->sent = $message;
        return MessageResult::accepted('wamid.TEST123');
    }

    public function testConnection(): bool
    {
        return true;
    }
}

function messengerFor(
    FakeContainer $container,
    InMemoryMemberRepository $repo
): MemberMessenger {
    return new MemberMessenger($container, $repo);
}

it('dispatches a text and audits it', function () {
    $driver = new CapturingMessageService();
    $audit = new SpyAuditLogger();
    $container = new FakeContainer([
        MessageService::class => $driver,
        AuditLogger::class => $audit,
    ]);
    $repo = new InMemoryMemberRepository([new MemberStub(id: 7, anonymousName: 'Anon G', mobileNumber: '+447700900123')]);

    $result = messengerFor($container, $repo)->sendTextToMember(7, 'Hello there');

    // Dispatched to the driver with a recipient built from the member.
    expect($driver->sent)->not->toBeNull()
        ->and($driver->sent->getTo()->getPhone())->toBe('+447700900123')
        ->and($driver->sent->getTo()->getMemberId())->toBe(7)
        ->and($driver->sent->getBody())->toBe('Hello there')
        ->and($result->getMessageId())->toBe('wamid.TEST123');

    // Exactly one audit entry, action "message", member entity.
    expect($audit->entries)->toHaveCount(1);
    $entry = $audit->entries[0];
    expect($entry['action'])->toBe('message')
        ->and($entry['action'])->toBe(MemberMessenger::AUDIT_ACTION)
        ->and($entry['entityType'])->toBe('member')
        ->and($entry['entityId'])->toBe(7)
        ->and($entry['fieldName'])->toBe('mobile_number');
    // Detail is non-PII: must not contain the number or the body.
    expect($entry['detail'])->not->toContain('447700900123')
        ->and($entry['detail'])->not->toContain('Hello there')
        ->and($entry['detail'])->toContain('wamid.TEST123');
});

it('dispatches a template', function () {
    $driver = new CapturingMessageService();
    $container = new FakeContainer([
        MessageService::class => $driver,
        AuditLogger::class => new SpyAuditLogger(),
    ]);
    $repo = new InMemoryMemberRepository([new MemberStub(id: 7, anonymousName: 'Anon G', mobileNumber: '+447700900123')]);

    messengerFor($container, $repo)
        ->sendTemplateToMember(7, 'shift_reminder', 'en_GB', ['1 hour']);

    expect($driver->sent)->not->toBeNull()
        ->and($driver->sent->isTemplate())->toBeTrue()
        ->and($driver->sent->getTemplateName())->toBe('shift_reminder')
        ->and($driver->sent->getTemplateParams())->toBe(['1 hour']);
});

it('throws when no driver is bound', function () {
    $container = new FakeContainer([
        AuditLogger::class => new SpyAuditLogger(),
    ]);
    $repo = new InMemoryMemberRepository([new MemberStub(id: 7, anonymousName: 'Anon G', mobileNumber: '+447700900123')]);

    messengerFor($container, $repo)->sendTextToMember(7, 'Hello');
})->throws(MessagingException::class, 'No message driver is bound');

it('throws for an unknown member', function () {
    $container = new FakeContainer([
        MessageService::class => new CapturingMessageService(),
        AuditLogger::class => new SpyAuditLogger(),
    ]);
    $repo = new InMemoryMemberRepository(); // empty

    messengerFor($container, $repo)->sendTextToMember(7, 'Hello');
})->throws(MessagingException::class, 'No member found with ID 7');

it('throws for a member without a mobile number', function () {
    $container = new FakeContainer([
        MessageService::class => new CapturingMessageService(),
        AuditLogger::class => new SpyAuditLogger(),
    ]);
    $repo = new InMemoryMemberRepository([new MemberStub(id: 7, anonymousName: 'Anon G', mobileNumber: '   ')]);

    messengerFor($container, $repo)->sendTextToMember(7, 'Hello');
})->throws(MessagingException::class, 'no mobile number');

it('sends even if the audit logger is missing', function () {
    // No AuditLogger bound — the send must still go through (the audit
    // step degrades to a logged warning, not a failure).
    $driver = new CapturingMessageService();
    $container = new FakeContainer([
        MessageService::class => $driver,
    ]);
    $repo = new InMemoryMemberRepository([new MemberStub(id: 7, anonymousName: 'Anon G', mobileNumber: '+447700900123')]);

    $result = messengerFor($container, $repo)->sendTextToMember(7, 'Hello');
    expect($result->getMessageId())->toBe('wamid.TEST123')
        ->and($driver->sent)->not->toBeNull();
});
