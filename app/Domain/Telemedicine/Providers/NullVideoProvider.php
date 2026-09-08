<?php

declare(strict_types=1);

namespace App\Domain\Telemedicine\Providers;

use App\Domain\Telemedicine\Contracts\VideoProvider;
use App\Domain\Telemedicine\Data\JoinToken;
use App\Domain\Telemedicine\Data\ProviderRoom;
use App\Domain\Telemedicine\Data\ProviderWebhookEvent;
use App\Domain\Telemedicine\Data\RoomSpec;
use App\Domain\Telemedicine\Data\TokenRequest;
use App\Domain\Telemedicine\Enums\TelemedicineProvider;
use App\Domain\Telemedicine\Services\Jwt;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

/**
 * The `log` driver: used by the whole test suite and by any deployment with no credentials configured. It never
 * opens a socket — BRIEF §8's suite must never reach a real service — but it is not a stub either: it mints a
 * REAL HS256 token with the same claims and the same TTL as LiveKit's, signed with the app key, so the token
 * scoping tests exercise the actual code path and the browser gets a token-shaped string to carry.
 *
 * `key()` answers `jitsi` because `telemedicine_rooms.provider` has a three-value CHECK (agora | livekit | jitsi)
 * and no `null`/`log` member: the null driver stands in for the self-hosted option, and the client resolves its
 * local-preview mode from the absence of a `server_url`, not from the stored provider name.
 */
final class NullVideoProvider implements VideoProvider
{
    public function __construct(
        private readonly string $secret,
        private readonly TelemedicineProvider $recordsAs = TelemedicineProvider::Jitsi,
    ) {}

    public function key(): TelemedicineProvider
    {
        return $this->recordsAs;
    }

    public function isConfigured(): bool
    {
        return true;              // always usable: that is the point of the fallback
    }

    public function createRoom(RoomSpec $spec): ProviderRoom
    {
        Log::channel('clinical')->info('telemedicine.null.room_created', ['room' => $spec->roomName, 'max_minutes' => $spec->maxMinutes]);

        return new ProviderRoom(roomName: $spec->roomName, sid: 'null-'.substr(hash('sha256', $spec->roomName), 0, 16));
    }

    public function mintToken(TokenRequest $request): JoinToken
    {
        $now = CarbonImmutable::now();
        $expiresAt = $now->addSeconds($request->ttlSeconds);

        $token = Jwt::encode([
            'iss' => 'bp-null',
            'sub' => $request->identity,
            'name' => $request->displayName,
            'nbf' => $now->getTimestamp() - 5,
            'exp' => $expiresAt->getTimestamp(),
            'video' => [
                'room' => $request->roomName,
                'roomJoin' => true,
                'canPublish' => $request->canPublish,
                'canSubscribe' => $request->canSubscribe,
                'canPublishData' => $request->canPublishData,
                'roomRecord' => $request->canRecord,
                'roomAdmin' => $request->isModerator,
            ],
        ], $this->secret);

        return new JoinToken(
            provider: $this->key(),
            roomName: $request->roomName,
            role: $request->role,
            identity: $request->identity,
            token: $token,
            expiresAt: $expiresAt,
            serverUrl: null,           // no server ⇒ the client runs its local-preview mode
        );
    }

    public function revoke(string $roomName, string $identity): void
    {
        Log::channel('clinical')->info('telemedicine.null.revoked', ['room' => $roomName, 'identity' => $identity]);
    }

    public function closeRoom(string $roomName): void
    {
        Log::channel('clinical')->info('telemedicine.null.room_closed', ['room' => $roomName]);
    }

    public function verifyWebhook(string $payload, array $headers): ?ProviderWebhookEvent
    {
        return null;
    }
}
