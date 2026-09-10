<?php

declare(strict_types=1);

namespace App\Domain\SaaS\Data;

/**
 * A one-time secret the console shows the operator exactly once — a temporary password, or a set-password link
 * for a clinic user. It travels through the session flash from the action's redirect to the next page load and is
 * pulled (not read) there, so a refresh cannot show it twice. It is never written to the audit log.
 */
final readonly class CredentialReveal
{
    public const KIND_PASSWORD = 'password';

    public const KIND_LINK = 'link';

    public const SESSION_KEY = 'super.tenants.reveal';

    public function __construct(
        public string $kind,
        public string $value,
        public string $userName,
        public string $email,
        public ?string $expiresAt = null,
    ) {}

    /** @return array{kind: string, value: string, user_name: string, email: string, expires_at: string|null} */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind,
            'value' => $this->value,
            'user_name' => $this->userName,
            'email' => $this->email,
            'expires_at' => $this->expiresAt,
        ];
    }

    public static function fromArray(mixed $raw): ?self
    {
        if (! is_array($raw) || ! is_string($raw['kind'] ?? null) || ! is_string($raw['value'] ?? null)) {
            return null;
        }

        return new self(
            kind: $raw['kind'],
            value: $raw['value'],
            userName: (string) ($raw['user_name'] ?? ''),
            email: (string) ($raw['email'] ?? ''),
            expiresAt: is_string($raw['expires_at'] ?? null) ? $raw['expires_at'] : null,
        );
    }
}
