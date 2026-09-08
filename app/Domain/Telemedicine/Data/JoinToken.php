<?php

declare(strict_types=1);

namespace App\Domain\Telemedicine\Data;

use App\Domain\Telemedicine\Enums\ParticipantRole;
use App\Domain\Telemedicine\Enums\TelemedicineProvider;
use Carbon\CarbonImmutable;

/** A minted, short-lived credential. Never persisted (SCHEMA §3.8: "tokens are minted on demand, never stored"). */
final readonly class JoinToken
{
    public function __construct(
        public TelemedicineProvider $provider,
        public string $roomName,
        public ParticipantRole $role,
        public string $identity,
        public string $token,
        public CarbonImmutable $expiresAt,
        public ?string $serverUrl = null,
        public ?string $joinUrl = null,
    ) {}

    /** @return array<string, mixed> the wire shape the join page receives */
    public function toArray(): array
    {
        return [
            'provider' => $this->provider->value,
            'room' => $this->roomName,
            'role' => $this->role->value,
            'identity' => $this->identity,
            'token' => $this->token,
            'expires_at' => $this->expiresAt->toIso8601String(),
            'server_url' => $this->serverUrl,
            'join_url' => $this->joinUrl,
        ];
    }
}
