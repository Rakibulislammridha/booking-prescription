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
use App\Domain\Telemedicine\Services\ProviderCredentials;
use Carbon\CarbonImmutable;

/**
 * Jitsi Meet — the zero-cost, self-hosted option (BRIEF §5.K). A clinic that will not pay per minute points
 * `telemedicine.host` at its own `meet.example.org` and gets the same flow.
 *
 * There is no room API: a Jitsi room exists the moment someone joins it, so `createRoom()` only computes the URL
 * and `closeRoom()` is a no-op the module still calls (the contract stays honest across drivers). Authorisation
 * is the `lib-jitsi-meet` JWT: `room` pins the one room, and `context.user.moderator` is what separates the
 * doctor from the patient — a patient token can never start a recording or eject anyone.
 *
 * Self-hosted Jitsi has no first-party webhook; `verifyWebhook()` therefore returns null and the module falls
 * back to its own client-reported join/leave beacons, which is why those exist at all.
 */
final class JitsiProvider implements VideoProvider
{
    public function __construct(private readonly ProviderCredentials $credentials) {}

    public function key(): TelemedicineProvider
    {
        return TelemedicineProvider::Jitsi;
    }

    public function isConfigured(): bool
    {
        return $this->credentials->host !== '' && $this->credentials->apiKey !== '' && $this->credentials->apiSecret !== '';
    }

    public function createRoom(RoomSpec $spec): ProviderRoom
    {
        return new ProviderRoom(roomName: $spec->roomName, sid: null, url: $this->domain());
    }

    public function mintToken(TokenRequest $request): JoinToken
    {
        $now = CarbonImmutable::now();
        $expiresAt = $now->addSeconds($request->ttlSeconds);

        $token = Jwt::encode([
            'aud' => 'jitsi',
            'iss' => $this->credentials->apiKey,
            'sub' => $this->domain(),
            'room' => $request->roomName,
            'nbf' => $now->getTimestamp() - 5,
            'exp' => $expiresAt->getTimestamp(),
            'context' => [
                'user' => [
                    'id' => $request->identity,
                    'name' => $request->displayName,
                    'moderator' => $request->isModerator ? 'true' : 'false',
                ],
                'features' => [
                    'recording' => $request->canRecord,
                    'livestreaming' => false,
                    'transcription' => false,
                    'outbound-call' => false,
                ],
            ],
        ], $this->credentials->apiSecret);

        return new JoinToken(
            provider: $this->key(),
            roomName: $request->roomName,
            role: $request->role,
            identity: $request->identity,
            token: $token,
            expiresAt: $expiresAt,
            serverUrl: 'https://'.$this->domain(),
            joinUrl: 'https://'.$this->domain().'/'.rawurlencode($request->roomName).'?jwt='.$token,
        );
    }

    /** A Jitsi token cannot be revoked server-side; it expires. The short TTL is the revocation story. */
    public function revoke(string $roomName, string $identity): void {}

    public function closeRoom(string $roomName): void {}

    public function verifyWebhook(string $payload, array $headers): ?ProviderWebhookEvent
    {
        return null;
    }

    private function domain(): string
    {
        return rtrim(preg_replace('#^https?://#', '', $this->credentials->host) ?? '', '/');
    }
}
