# Rabbit — Member-Messaging Contracts

[![CI](https://github.com/bleedingdeacons/rabbit/actions/workflows/ci.yml/badge.svg?branch=main)](https://github.com/bleedingdeacons/rabbit/actions/workflows/ci.yml)
[![Semgrep](https://github.com/bleedingdeacons/rabbit/actions/workflows/semgrep.yml/badge.svg?branch=main)](https://github.com/bleedingdeacons/rabbit/actions/workflows/semgrep.yml)
[![Coverage Status](https://coveralls.io/repos/github/bleedingdeacons/rabbit/badge.svg?branch=main)](https://coveralls.io/github/bleedingdeacons/rabbit?branch=main)
![PHPStan](https://img.shields.io/badge/dynamic/yaml?url=https%3A%2F%2Fraw.githubusercontent.com%2Fbleedingdeacons%2Frabbit%2Fmain%2Fphpstan.neon.dist&query=%24.parameters.level&label=PHPStan&prefix=level%20&color=brightgreen)
![PHPCS](https://img.shields.io/badge/dynamic/xml?url=https%3A%2F%2Fraw.githubusercontent.com%2Fbleedingdeacons%2Frabbit%2Fmain%2F.phpcs.xml.dist&query=%2Fruleset%2Frule%5B1%5D%2F%40ref&label=PHPCS&color=brightgreen)
![Version](https://img.shields.io/github/v/tag/bleedingdeacons/rabbit?label=version&color=blue)
![PHP](https://img.shields.io/badge/php-8.4%2B-777bb4)
![Licence](https://img.shields.io/badge/licence-MIT%20(Modified)-green)

Outbound-messaging contracts for sending to **Unity** members. **A Composer library, not a WordPress plugin** — it is never activated. [WhatsApp](https://github.com/bleedingdeacons/whatsapp) (the driver for Meta's WhatsApp Business Cloud API) `require`s it, and it is loaded by WhatsApp's own Composer autoloader.

Until v2.1.0 Rabbit was a plugin of its own that booted on `unity/loaded`, registered `MemberMessenger` into Unity's container and fired `rabbit/loaded` for a driver to bind on. It became a library so messaging stops needing a separate plugin to be installed and activated alongside the one that actually does the work — the same change Beacon made for call forwarding.

## How a driver reaches a consumer

```
Unity (plugins_loaded) ──unity/loaded──▶ WhatsApp ──registers──▶ Unity's container ◀──get── any plugin on unity/loaded
                                                      MessageService
                                                      MemberMessenger
```

- **WhatsApp** boots on `unity/loaded` and registers its `MessageService` driver, the HTTP transport and this library's `MemberMessenger` into Unity's shared container.
- **A consumer** resolves `MemberMessenger` from that same container — `unity()->get(MemberMessenger::class)` — rather than from a container of Rabbit's own.

Unlike Beacon, Rabbit ships no registry. Beacon needed `ForwardingRegistry` because Tamar wires its driver into a container of its own, which Trusted cannot see. `MemberMessenger` cannot work without Unity's member repository or Scrutiny's audit logger, both of which live in Unity's container, so the driver registers there and every consumer can already reach it.

**Keep vendored copies compatible.** A PHP class loads once per request, so if a second plugin ever vendors Rabbit, whichever autoloader loads a class first supplies it to both. They should require the same major version, and a breaking change here is a new major that they move to together.

## What it ships

| | |
|---|---|
| `Rabbit\Messaging\Interfaces\MessageService` | The driver contract: `send(Message): MessageResult`, `testConnection(): bool`. |
| `Rabbit\Messaging\Interfaces\MessagingException` | Common throwable for driver failures. |
| `Rabbit\Messaging\Models\Message` | Immutable text/template message. `Message::text()`, `Message::template()`. |
| `Rabbit\Messaging\Models\Recipient` | Immutable recipient (phone, name, member id). |
| `Rabbit\Messaging\Models\MessageResult` | Immutable accepted result (provider message id, status). |
| `Rabbit\Messaging\AbstractMessageService` | Shared validation + phone normalisation drivers extend. |
| `Rabbit\Members\MemberMessenger` | **The headline helper**: member → message → bound driver + Scrutiny audit. |
| `Rabbit\Transport\…` | `HttpTransport` and `HttpTransportFactory` contracts, the WordPress HTTP API implementation, and the `UserAgent` builder. |
| `Rabbit\Capabilities\CapabilityBootstrap` | The messaging roles and capabilities below. |

The capabilities are classes only. **The driver plugin wires them**: WhatsApp registers the roles on activation, removes them on deactivation and uninstall, and re-registers them if they go missing.

## Usage

```php
use Rabbit\Members\MemberMessenger;

// Send a free-form text message to Unity member #123.
unity()->get(MemberMessenger::class)
    ->sendTextToMember(123, 'Your shift starts in 1 hour.');

// Send a pre-approved template message.
unity()->get(MemberMessenger::class)
    ->sendTemplateToMember(123, 'shift_reminder', 'en_GB', ['1 hour']);
```

`MemberMessenger` resolves the member's mobile number from Unity, dispatches via
whatever driver is bound, and writes a Scrutiny audit entry (action `message`,
entity `member`, field `mobile_number`) — non-PII detail only.

## Installation

In the consuming plugin's `composer.json`:

```json
"repositories": [
    { "type": "vcs", "url": "https://github.com/bleedingdeacons/rabbit" }
],
"require": {
    "bleedingdeacons/rabbit": "^2.1"
}
```

Releases are `vX.Y.Z` tags cut by hand on `main`. There is no zip and no GitHub Release asset.

## Capabilities

| Capability | Granted to |
|---|---|
| `rabbit_manage_messaging` | Operator only — configure the provider connection. |
| `rabbit_send_message` | Operator + Sender — send messages to members. |
| `rabbit_view_messaging` | Operator + Sender + Viewer — view messaging status / settings. |

Roles are `rabbit_operator`, `rabbit_sender` and `rabbit_viewer`; administrators inherit all three capabilities.

## Requirements

- WordPress 6.1+
- PHP 8.4+
- Unity and Scrutiny, for `MemberMessenger`

## Testing

Install the dev dependencies and run the suite from the repository root. The
tests load Unity's and Scrutiny's interfaces and test doubles from sibling
checkouts at `../unity` and `../scrutiny`, as CI arranges.

```bash
composer install
```

| Command | Description |
|---|---|
| `composer test` | Run the full Pest test suite |
| `composer test:unit` | Run unit tests only |
| `composer test:integration` | Run integration tests only |
| `composer test:coverage` | Generate an HTML coverage report |
| `composer phpstan` | Run PHPStan static analysis |
| `composer phpcs` | Check coding standards |
| `composer phpcs:fix` | Auto-fix coding standard violations |
| `composer check` | Run CS + PHPStan + tests in sequence |

Line coverage is reported to [Coveralls](https://coveralls.io/github/bleedingdeacons/rabbit?branch=main)
on every CI run — see the coverage badge at the top of this file.

The suite is written in [Pest](https://pestphp.com) (running on PHPUnit) and
lives in `tests/Unit/`. Run it with Pest, not PHPUnit directly —
`vendor/bin/phpunit` cannot load Pest's closure-based files.

## License

MIT (Modified — No Resale). © The Bleeding Deacons.
