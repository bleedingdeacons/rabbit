=== Rabbit ===
Contributors: thebleedingdeacons
Tags: messaging, contracts, interfaces, members, notifications
Requires at least: 6.1
Tested up to: 6.9
Stable tag: 1.0.3
Build date: 2026/07/20 16:42:43
Requires PHP: 8.1
License: MIT (Modified — No Resale)

Framework for sending outbound messages to Unity members. Defines contracts; an implementation plugin provides the concrete driver.

== Description ==

Rabbit is the contract layer for sending messages to Unity members. It ships interfaces, value objects, a shared HTTP transport, and a high-level `MemberMessenger` helper; an implementation plugin (e.g. **WhatsApp**) provides the concrete driver and wires everything into Unity's shared container.

Rabbit itself never opens a socket and never knows about any provider — it only defines the shape every driver must satisfy, plus the glue that turns a Unity member into a sent message and records the send in Scrutiny's GDPR audit log. This separation lets you swap providers (or stand up tests with mocks) without touching consumers.

**Key components:**

* `Rabbit\Messaging\Interfaces\MessageService` — the driver contract (send a message, test the connection).
* `Rabbit\Messaging\Interfaces\MessagingException` — common throwable for driver failures.
* `Rabbit\Messaging\Models\Message` — immutable value object: a text or template message.
* `Rabbit\Messaging\Models\Recipient` — immutable value object: who the message goes to.
* `Rabbit\Messaging\Models\MessageResult` — immutable value object: the provider's accepted result.
* `Rabbit\Messaging\AbstractMessageService` — shared validation + number normalisation drivers can extend.
* `Rabbit\Members\MemberMessenger` — the headline helper: Unity member → message → bound driver, with a Scrutiny audit entry.
* `Rabbit\Transport\Interfaces\HttpTransport` — abstract HTTP layer so drivers stay testable.

== Requirements ==

* **Unity** — provides member data and the shared container.
* **Scrutiny** — provides the GDPR audit log. Every message sent to a member is recorded with the `message` action.

== Installation ==

1. Upload the `rabbit` directory to `/wp-content/plugins/`.
2. Activate Rabbit through the **Plugins** menu in WordPress (Unity and Scrutiny must be active first).
3. Install and activate an implementation plugin (e.g. WhatsApp) — Rabbit alone does nothing visible until a driver is bound.

== Frequently Asked Questions ==

= Does Rabbit do anything on its own? =

No. Rabbit ships only contracts and the member/audit glue. You must install an implementation plugin that binds a concrete driver on the `rabbit/loaded` action.

= How do I send a message to a member? =

`rabbit()->get(\Rabbit\Members\MemberMessenger::class)->sendTextToMember($memberId, 'Your message');`

= How do I disable Rabbit without deactivating it? =

Define `RABBIT_KILL` as `true` in `wp-config.php`. Rabbit stands down and `rabbit/loaded` never fires, so downstream drivers stand down too.

== Changelog ==

= 1.0.0 =
* Initial release: messaging contracts, value objects, HTTP transport, capabilities, and the `MemberMessenger` helper with Scrutiny audit logging.
