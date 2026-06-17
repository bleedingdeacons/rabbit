<?php

declare(strict_types=1);

namespace Rabbit\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Rabbit\Members\MemberMessenger;
use Rabbit\Messaging\Interfaces\MessageService;
use Rabbit\Messaging\Interfaces\MessagingException;
use Rabbit\Messaging\Models\Message;
use Rabbit\Messaging\Models\MessageResult;
use Scrutiny\Audit\Interfaces\AuditLogger;
use Unity\Members\Interfaces\Member;
use Unity\Members\Interfaces\MemberRepository;

/** Minimal PSR-11 container backed by a map. */
final class FakeContainer implements ContainerInterface
{
    /** @param array<string,mixed> $entries */
    public function __construct(private array $entries = [])
    {
    }

    public function set(string $id, mixed $value): void
    {
        $this->entries[$id] = $value;
    }

    public function get(string $id): mixed
    {
        if (!array_key_exists($id, $this->entries)) {
            throw new \RuntimeException("no entry for {$id}");
        }
        return $this->entries[$id];
    }

    public function has(string $id): bool
    {
        return array_key_exists($id, $this->entries);
    }
}

final class FakeMember implements Member
{
    public function __construct(
        private int $id,
        private string $name,
        private string $mobile,
    ) {
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getAnonymousName(): string
    {
        return $this->name;
    }

    public function getMobileNumber(): string
    {
        return $this->mobile;
    }
}

final class FakeMemberRepository implements MemberRepository
{
    /** @param array<int,Member> $members */
    public function __construct(private array $members = [])
    {
    }

    public function findById(int $id): ?Member
    {
        return $this->members[$id] ?? null;
    }
}

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

final class CapturingAuditLogger implements AuditLogger
{
    /** @var array<int,array<string,mixed>> */
    public array $entries = [];

    public function log(string $action, string $entityType, int $entityId, string $fieldName, string $detail = ''): void
    {
        $this->entries[] = compact('action', 'entityType', 'entityId', 'fieldName', 'detail');
    }

    public function logBatch(string $action, string $entityType, int $entityId, array $fieldNames, string $detail = ''): void
    {
        foreach ($fieldNames as $f) {
            $this->log($action, $entityType, $entityId, $f, $detail);
        }
    }
}

final class MemberMessengerTest extends TestCase
{
    private function makeRabbit(
        FakeContainer $container,
        FakeMemberRepository $repo
    ): MemberMessenger {
        return new MemberMessenger($container, $repo);
    }

    public function test_send_text_dispatches_and_audits(): void
    {
        $driver = new CapturingMessageService();
        $audit = new CapturingAuditLogger();
        $container = new FakeContainer([
            MessageService::class => $driver,
            AuditLogger::class => $audit,
        ]);
        $repo = new FakeMemberRepository([
            7 => new FakeMember(7, 'Anon G', '+447700900123'),
        ]);

        $result = $this->makeRabbit($container, $repo)->sendTextToMember(7, 'Hello there');

        // Dispatched to the driver with a recipient built from the member.
        $this->assertNotNull($driver->sent);
        $this->assertSame('+447700900123', $driver->sent->getTo()->getPhone());
        $this->assertSame(7, $driver->sent->getTo()->getMemberId());
        $this->assertSame('Hello there', $driver->sent->getBody());
        $this->assertSame('wamid.TEST123', $result->getMessageId());

        // Exactly one audit entry, action "message", member entity.
        $this->assertCount(1, $audit->entries);
        $entry = $audit->entries[0];
        $this->assertSame('message', $entry['action']);
        $this->assertSame(MemberMessenger::AUDIT_ACTION, $entry['action']);
        $this->assertSame('member', $entry['entityType']);
        $this->assertSame(7, $entry['entityId']);
        $this->assertSame('mobile_number', $entry['fieldName']);
        // Detail is non-PII: must not contain the number or the body.
        $this->assertStringNotContainsString('447700900123', $entry['detail']);
        $this->assertStringNotContainsString('Hello there', $entry['detail']);
        $this->assertStringContainsString('wamid.TEST123', $entry['detail']);
    }

    public function test_send_template_dispatches(): void
    {
        $driver = new CapturingMessageService();
        $container = new FakeContainer([
            MessageService::class => $driver,
            AuditLogger::class => new CapturingAuditLogger(),
        ]);
        $repo = new FakeMemberRepository([
            7 => new FakeMember(7, 'Anon G', '+447700900123'),
        ]);

        $this->makeRabbit($container, $repo)
            ->sendTemplateToMember(7, 'shift_reminder', 'en_GB', ['1 hour']);

        $this->assertNotNull($driver->sent);
        $this->assertTrue($driver->sent->isTemplate());
        $this->assertSame('shift_reminder', $driver->sent->getTemplateName());
        $this->assertSame(['1 hour'], $driver->sent->getTemplateParams());
    }

    public function test_no_driver_bound_throws(): void
    {
        $container = new FakeContainer([
            AuditLogger::class => new CapturingAuditLogger(),
        ]);
        $repo = new FakeMemberRepository([
            7 => new FakeMember(7, 'Anon G', '+447700900123'),
        ]);

        $this->expectException(MessagingException::class);
        $this->expectExceptionMessage('No message driver is bound');
        $this->makeRabbit($container, $repo)->sendTextToMember(7, 'Hello');
    }

    public function test_unknown_member_throws(): void
    {
        $container = new FakeContainer([
            MessageService::class => new CapturingMessageService(),
            AuditLogger::class => new CapturingAuditLogger(),
        ]);
        $repo = new FakeMemberRepository([]); // empty

        $this->expectException(MessagingException::class);
        $this->expectExceptionMessage('No member found with ID 7');
        $this->makeRabbit($container, $repo)->sendTextToMember(7, 'Hello');
    }

    public function test_member_without_mobile_throws(): void
    {
        $container = new FakeContainer([
            MessageService::class => new CapturingMessageService(),
            AuditLogger::class => new CapturingAuditLogger(),
        ]);
        $repo = new FakeMemberRepository([
            7 => new FakeMember(7, 'Anon G', '   '),
        ]);

        $this->expectException(MessagingException::class);
        $this->expectExceptionMessage('no mobile number');
        $this->makeRabbit($container, $repo)->sendTextToMember(7, 'Hello');
    }

    public function test_send_succeeds_even_if_audit_logger_missing(): void
    {
        // No AuditLogger bound — the send must still go through (the audit
        // step degrades to a logged warning, not a failure).
        $driver = new CapturingMessageService();
        $container = new FakeContainer([
            MessageService::class => $driver,
        ]);
        $repo = new FakeMemberRepository([
            7 => new FakeMember(7, 'Anon G', '+447700900123'),
        ]);

        $result = $this->makeRabbit($container, $repo)->sendTextToMember(7, 'Hello');
        $this->assertSame('wamid.TEST123', $result->getMessageId());
        $this->assertNotNull($driver->sent);
    }
}
