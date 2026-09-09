<?php

declare(strict_types=1);

namespace App\Domain\Telemedicine\Data;

/**
 * A DECODED Agora AccessToken2 (version 007). The counterpart of `Jwt::decode()` for the one driver whose
 * credential is a packed binary structure rather than a JWT: it exists so the suite can assert what a minted
 * token actually GRANTS — appId, issue time, expiry, salt, channel, uid and the per-service privilege map —
 * instead of asserting that a base64 string is non-empty.
 *
 * `message` is the exact signed byte range and `signature` the HMAC over it, so a decoded token can be
 * re-verified without re-packing it (`AgoraAccessToken::verify()`).
 */
final readonly class AgoraToken
{
    /**
     * @param  array<int, array{type: int, privileges: array<int, int>, channel: string, uid: string}>  $services
     */
    public function __construct(
        public string $appId,
        public int $issuedAt,
        public int $expireSeconds,
        public int $salt,
        public array $services,
        public string $signature,
        public string $message,
    ) {}

    /** @return array{type: int, privileges: array<int, int>, channel: string, uid: string}|null */
    public function service(int $type): ?array
    {
        foreach ($this->services as $service) {
            if ($service['type'] === $type) {
                return $service;
            }
        }

        return null;
    }

    public function hasService(int $type): bool
    {
        return $this->service($type) !== null;
    }

    /** @return array<int, int> the service's privilege ⇒ expiry-in-seconds map, empty when it carries no such service */
    public function privileges(int $type): array
    {
        return $this->service($type)['privileges'] ?? [];
    }

    /** Expiry (seconds from `issuedAt`) of one privilege of one service, or null when it was not granted. */
    public function privilege(int $type, int $privilege): ?int
    {
        return $this->privileges($type)[$privilege] ?? null;
    }

    public function grants(int $type, int $privilege): bool
    {
        return $this->privilege($type, $privilege) !== null;
    }
}
