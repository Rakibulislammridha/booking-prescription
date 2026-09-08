<?php

declare(strict_types=1);

namespace App\Domain\Telemedicine\Contracts;

use App\Domain\Telemedicine\Data\JoinToken;
use App\Domain\Telemedicine\Data\ProviderRoom;
use App\Domain\Telemedicine\Data\ProviderWebhookEvent;
use App\Domain\Telemedicine\Data\RoomSpec;
use App\Domain\Telemedicine\Data\TokenRequest;
use App\Domain\Telemedicine\Enums\TelemedicineProvider;

/**
 * The provider-agnostic video contract (BRIEF §5.K: "Agora, LiveKit, or Jitsi"). Five verbs, no more:
 * create a room, mint a scoped short-lived join token per participant, revoke one, close the room, and
 * verify an inbound webhook.
 *
 * Implementations MUST NOT decide who may join — that is the module's job (plan, guard, signature, room status).
 * They translate an already-authorised `TokenRequest` into the provider's own credential, and nothing more.
 */
interface VideoProvider
{
    /** The value written to `telemedicine_rooms.provider` (SCHEMA §3.8 CHECK: agora | livekit | jitsi). */
    public function key(): TelemedicineProvider;

    /** True when real credentials are configured; false makes the module fall back to the null driver. */
    public function isConfigured(): bool;

    /** Idempotent: creating an existing room returns it. */
    public function createRoom(RoomSpec $spec): ProviderRoom;

    /** A credential scoped to one room, one identity, one role, for `ttlSeconds`. */
    public function mintToken(TokenRequest $request): JoinToken;

    /** Remove one participant and invalidate their session (the doctor ending a call, an expired link). */
    public function revoke(string $roomName, string $identity): void;

    /** Tear the room down at the provider once the consultation is over. */
    public function closeRoom(string $roomName): void;

    /**
     * Authenticate and normalise a provider callback. Returns null when the signature does not verify or the
     * provider does not send webhooks at all.
     *
     * @param  array<string, string>  $headers
     */
    public function verifyWebhook(string $payload, array $headers): ?ProviderWebhookEvent;
}
