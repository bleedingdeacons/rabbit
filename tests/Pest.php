<?php

declare(strict_types=1);

// Pest configuration.
//
// Every test file in this suite ran on wp-mocks' TestCase before the move to
// Pest, and every one still does. Most of them are pure value-object tests
// that would pass on plain PHPUnit, but two reach WordPress: UserAgent asks
// home_url() and WpHttpTransport goes through wp_remote_request(), both of
// which are wp-mocks stubs whose state that TestCase resets between tests.
// Binding the whole directory reproduces the original split exactly — there
// was no plain-PHPUnit half to preserve.
//
// So a new test file dropped into tests/Unit gets Brain Monkey's lifecycle,
// Mockery integration and WpState resets without asking for them.

use BleedingDeacons\WpMocks\TestCase;

pest()->extend(TestCase::class)->in('Unit');
