# Rabbit

[![CI](https://github.com/bleedingdeacons/rabbit/actions/workflows/ci.yml/badge.svg?branch=main)](https://github.com/bleedingdeacons/rabbit/actions/workflows/ci.yml)
![PHPStan](https://img.shields.io/badge/PHPStan-level%205-brightgreen)
![Version](https://img.shields.io/badge/version-1.0.1-blue)
![PHP](https://img.shields.io/badge/php-8.1%2B-777bb4)
![Licence](https://img.shields.io/badge/licence-MIT%20(Modified)-green)

Framework for sending outbound messages to **Unity** members. Rabbit is the
*contracts* plugin: it defines the messaging interfaces, value objects, a shared
HTTP transport, capabilities, and the high-level `MemberMessenger` helper. An
**implementation plugin** (e.g. [WhatsApp](https://github.com/thebleedingdeacons/whatsapp))
binds a concrete driver against the contract.

Rabbit does nothing visible on its own — it never talks to a provider. It only
defines the shape every driver must satisfy and the glue that turns a Unity member
into a sent message (recording each send in Scrutiny's GDPR audit log).

## Architecture

```
Unity (members + container)
└── Scrutiny (GDPR audit log)
    └── Rabbit (contracts + MemberMessenger)   ← this plugin
        └── WhatsApp (driver: Meta Cloud API)
```

Rabbit boots on `unity/loaded`, registers its services into Unity's shared
container, and fires `rabbit/loaded` so driver plugins can bind their concrete
`MessageService`. It hard-requires **Unity** (member data) and **Scrutiny** (audit
log).

## Key components

| Class | Responsibility |
|---|---|
| `Rabbit\Messaging\Interfaces\MessageService` | The driver contract: `send(Message): MessageResult`, `testConnection(): bool`. |
| `Rabbit\Messaging\Interfaces\MessagingException` | Common throwable for driver failures. |
| `Rabbit\Messaging\Models\Message` | Immutable text/template message. `Message::text()`, `Message::template()`. |
| `Rabbit\Messaging\Models\Recipient` | Immutable recipient (phone, name, member id). |
| `Rabbit\Messaging\Models\MessageResult` | Immutable accepted result (provider message id, status). |
| `Rabbit\Messaging\AbstractMessageService` | Shared validation + phone normalisation drivers extend. |
| `Rabbit\Members\MemberMessenger` | **The headline helper**: member → message → bound driver + Scrutiny audit. |
| `Rabbit\Transport\Interfaces\HttpTransport` | Abstract HTTP layer so drivers stay testable. |

## Usage

```php
// Send a free-form text message to Unity member #123.
rabbit()
    ->get(\Rabbit\Members\MemberMessenger::class)
    ->sendTextToMember(123, 'Your shift starts in 1 hour.');

// Send a pre-approved template message.
rabbit()
    ->get(\Rabbit\Members\MemberMessenger::class)
    ->sendTemplateToMember(123, 'shift_reminder', 'en_GB', ['1 hour']);
```

`MemberMessenger` resolves the member's mobile number from Unity, dispatches via
whatever driver is bound, and writes a Scrutiny audit entry (action `message`,
entity `member`, field `mobile_number`) — non-PII detail only.

## Capabilities

| Capability | Meaning |
|---|---|
| `rabbit_manage_messaging` | Configure the provider connection. |
| `rabbit_send_message` | Send messages to members. |
| `rabbit_view_messaging` | View messaging status / settings. |

Roles `rabbit_operator`, `rabbit_sender`, `rabbit_viewer` are created on
activation; administrators inherit all three capabilities.

## Kill switch

Define `RABBIT_KILL` as `true` in `wp-config.php` to stand Rabbit down
without deactivating it.

## Development

```bash
composer install
composer test    # PHPUnit unit tests
composer stan    # PHPStan
composer cs      # PHP_CodeSniffer (WordPress standard)
```

## License

MIT (Modified — No Resale). © The Bleeding Deacons.
