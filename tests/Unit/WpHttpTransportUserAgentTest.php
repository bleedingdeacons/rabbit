<?php

declare(strict_types=1);

namespace Rabbit\Tests\Unit;

use BleedingDeacons\WpMocks\Doubles\FakeWpHttp;
use BleedingDeacons\WpMocks\TestCase;
use Rabbit\Transport\WpHttpTransport;

/**
 * Covers how {@see WpHttpTransport} introduces itself to an upstream.
 *
 * Scoped deliberately to the user-agent: the transport's wider
 * behaviour (cookie jar, header merging, failure mapping) is Beacon's
 * twin of this class and is exercised there.
 */
final class WpHttpTransportUserAgentTest extends TestCase
{
    protected function setUp(): void
    {
        FakeWpHttp::reset();
    }

    public function test_it_introduces_itself_as_rabbit_by_default(): void
    {
        FakeWpHttp::pushResponse(200, '');

        (new WpHttpTransport())->request('GET', 'https://graph.example.com/messages');

        self::assertSame(
            'Rabbit/9.9.9 (rest@aa-bristol.org; https://example.test)',
            FakeWpHttp::sentArgs(0)['user-agent'],
        );
    }

    public function test_a_driver_user_agent_overrides_the_rabbit_default(): void
    {
        // WhatsApp owns the conversation with the Graph API, so that is
        // what the upstream should see.
        FakeWpHttp::pushResponse(200, '');

        (new WpHttpTransport(userAgent: 'WhatsApp/1.2.3 (rest@aa-bristol.org; https://example.test)'))
            ->request('GET', 'https://graph.example.com/messages');

        self::assertSame(
            'WhatsApp/1.2.3 (rest@aa-bristol.org; https://example.test)',
            FakeWpHttp::sentArgs(0)['user-agent'],
        );
    }
}
