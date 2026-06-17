<?php

declare(strict_types=1);

namespace Rabbit\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Rabbit\Messaging\Models\Recipient;

final class RecipientTest extends TestCase
{
    public function test_to_builds_recipient(): void
    {
        $r = Recipient::to('+44 7700 900123', 'Anon A', 42);
        $this->assertSame('+44 7700 900123', $r->getPhone());
        $this->assertSame('Anon A', $r->getName());
        $this->assertSame(42, $r->getMemberId());
        $this->assertTrue($r->hasMember());
    }

    public function test_ad_hoc_recipient_has_no_member(): void
    {
        $r = Recipient::to('+447700900123');
        $this->assertSame(0, $r->getMemberId());
        $this->assertFalse($r->hasMember());
    }

    public function test_describe_combines_name_and_number(): void
    {
        $this->assertSame('Anon A <+447700900123>', Recipient::to('+447700900123', 'Anon A')->describe());
        $this->assertSame('+447700900123', Recipient::to('+447700900123')->describe());
        $this->assertSame('Anon A', Recipient::to('', 'Anon A')->describe());
    }

    public function test_array_round_trip(): void
    {
        $r = Recipient::to('+447700900123', 'Anon A', 7);
        $copy = new Recipient($r->toArray());
        $this->assertEquals($r, $copy);
    }

    public function test_phone_is_trimmed(): void
    {
        $r = new Recipient(['phone' => '  +447700900123  ']);
        $this->assertSame('+447700900123', $r->getPhone());
    }
}
