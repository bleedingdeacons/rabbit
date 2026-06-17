<?php

declare(strict_types=1);

namespace Rabbit\Messaging\Models;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Immutable value object representing a single outbound message.
 *
 * A message is one of two shapes, distinguished by `type`:
 *
 *  - `text`     a free-form text body. Most providers only allow
 *               free-form text inside an open conversation window (the
 *               WhatsApp Cloud API, for instance, requires the recipient
 *               to have messaged you within the last 24 hours).
 *  - `template` a pre-registered, provider-approved template referenced
 *               by `templateName` + `templateLanguage`, with ordered
 *               `templateParams` substituted into its body. This is how
 *               you start a conversation / send outside the session
 *               window.
 *
 * Drivers that don't support a given type should throw
 * {@see \Rabbit\Messaging\Interfaces\MessagingException} rather than
 * silently degrading.
 *
 * `meta` is opaque to Rabbit and carried through for the caller's
 * own bookkeeping (e.g. the originating member ID for audit, a
 * correlation id). It is never sent to the provider.
 *
 * Immutability is enforced by `readonly`. Use {@see self::with()} to
 * derive a modified copy.
 */
final class Message
{
    public const TYPE_TEXT = 'text';
    public const TYPE_TEMPLATE = 'template';

    public readonly Recipient $to;
    public readonly string $type;
    public readonly string $body;
    public readonly string $templateName;
    public readonly string $templateLanguage;
    /** @var array<int,string> */
    public readonly array $templateParams;
    /** @var array<string,mixed> */
    public readonly array $meta;

    /**
     * @param array<string,mixed> $data
     */
    public function __construct(array $data)
    {
        $to = $data['to'] ?? [];
        $this->to = $to instanceof Recipient ? $to : new Recipient(is_array($to) ? $to : []);

        $type = (string) ($data['type'] ?? self::TYPE_TEXT);
        $this->type = in_array($type, [self::TYPE_TEXT, self::TYPE_TEMPLATE], true) ? $type : self::TYPE_TEXT;

        $this->body = (string) ($data['body'] ?? '');
        $this->templateName = (string) ($data['template_name'] ?? '');
        $this->templateLanguage = (string) ($data['template_language'] ?? '');

        $params = $data['template_params'] ?? [];
        $this->templateParams = is_array($params) ? array_values(array_map('strval', $params)) : [];

        $meta = $data['meta'] ?? [];
        $this->meta = is_array($meta) ? $meta : [];
    }

    /**
     * Build a free-form text message.
     *
     * @param array<string,mixed> $meta
     */
    public static function text(Recipient $to, string $body, array $meta = []): self
    {
        return new self([
            'to' => $to,
            'type' => self::TYPE_TEXT,
            'body' => $body,
            'meta' => $meta,
        ]);
    }

    /**
     * Build a template message.
     *
     * @param array<int,string>   $params Ordered body parameters.
     * @param array<string,mixed> $meta
     */
    public static function template(
        Recipient $to,
        string $name,
        string $language,
        array $params = [],
        array $meta = []
    ): self {
        return new self([
            'to' => $to,
            'type' => self::TYPE_TEMPLATE,
            'template_name' => $name,
            'template_language' => $language,
            'template_params' => $params,
            'meta' => $meta,
        ]);
    }

    public function getTo(): Recipient
    {
        return $this->to;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function isText(): bool
    {
        return $this->type === self::TYPE_TEXT;
    }

    public function isTemplate(): bool
    {
        return $this->type === self::TYPE_TEMPLATE;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    public function getTemplateName(): string
    {
        return $this->templateName;
    }

    public function getTemplateLanguage(): string
    {
        return $this->templateLanguage;
    }

    /** @return array<int,string> */
    public function getTemplateParams(): array
    {
        return $this->templateParams;
    }

    /** @return array<string,mixed> */
    public function getMeta(): array
    {
        return $this->meta;
    }

    /**
     * Return a new message with the given fields overridden.
     *
     * @param array<string,mixed> $changes
     */
    public function with(array $changes): self
    {
        return new self(array_merge($this->toArray(), $changes));
    }

    /**
     * Export to plain array (for JSON / templates / driver payloads).
     *
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'to' => $this->to->toArray(),
            'type' => $this->type,
            'body' => $this->body,
            'template_name' => $this->templateName,
            'template_language' => $this->templateLanguage,
            'template_params' => $this->templateParams,
            'meta' => $this->meta,
        ];
    }
}
