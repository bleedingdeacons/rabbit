<?php

declare(strict_types=1);

namespace Rabbit\Tests\Unit;

use BleedingDeacons\WpMocks\Doubles\FakeWpHttp;
use Rabbit\Transport\WpHttpTransport;

/*
 * Covers how {@see WpHttpTransport} introduces itself to an upstream.
 *
 * Scoped deliberately to the user-agent: the transport's wider
 * behaviour (cookie jar, header merging, failure mapping) is Beacon's
 * twin of this class and is exercised there.
 */

beforeEach(function () {
    FakeWpHttp::reset();
});

it('introduces itself as Rabbit by default', function () {
    FakeWpHttp::pushResponse(200, '');

    (new WpHttpTransport())->request('GET', 'https://graph.example.com/messages');

    // The version is whatever Composer installed, so only its presence is pinned.
    expect(FakeWpHttp::sentArgs(0)['user-agent'])
        ->toMatch('#^Rabbit/\S+ \(rest@aa-bristol\.org; https://example\.test\)$#');
});

it('lets a driver user agent override the Rabbit default', function () {
    // WhatsApp owns the conversation with the Graph API, so that is
    // what the upstream should see.
    FakeWpHttp::pushResponse(200, '');

    (new WpHttpTransport(userAgent: 'WhatsApp/1.2.3 (rest@aa-bristol.org; https://example.test)'))
        ->request('GET', 'https://graph.example.com/messages');

    expect(FakeWpHttp::sentArgs(0)['user-agent'])
        ->toBe('WhatsApp/1.2.3 (rest@aa-bristol.org; https://example.test)');
});
