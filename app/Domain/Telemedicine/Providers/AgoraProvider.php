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
use App\Domain\Telemedicine\Exceptions\VideoProviderFailed;
use App\Domain\Telemedicine\Services\AgoraAccessToken;
use App\Domain\Telemedicine\Services\ProviderCredentials;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;

/**
 * Agora RTC (https://docs.agora.io) — the third driver BRIEF §5.K names, and the one the module shipped without.
 *
 * The reason it was left out is real: an Agora credential is not a JWT. It is an **AccessToken2 (version 007)**
 * binary packing, so it could not reuse the `Jwt` helper LiveKit and Jitsi share. `AgoraAccessToken` is that
 * codec; this file is only the policy on top of it.
 *
 * TOKENS. `credentials.apiKey` is the **App ID** and `credentials.apiSecret` the **App Certificate** (both 32 hex
 * characters — `isConfigured()` checks exactly that, so a clinic that pasted half a certificate falls back to the
 * null driver instead of minting something Agora will reject). Two services go into a token:
 *
 *     RTC (1)         join-channel, publish-audio, publish-video, publish-data, each with its own expiry
 *                     BOTH roles — a consultation needs two faces, exactly as `canPublish` is true for both on
 *                     LiveKit. The channel and the uid pin the token to one room and one participant.
 *     Streaming (3)   publish-mix-stream, publish-raw-stream — pushing the session out of the channel, which is
 *                     Agora's nearest equivalent of LiveKit's `roomRecord`. DOCTOR ONLY, and only when the clinic
 *                     enabled recording (`TokenRequest::canRecord`, which `ParticipantRole::mayRecord()` already
 *                     refused to a patient). A patient token carries ONE service and can never carry this one.
 *
 * There is deliberately no `roomAdmin` equivalent, because Agora's token format has none: moderation is an
 * account-level REST operation (below), never a privilege inside a participant's credential. That is a stronger
 * guarantee than LiveKit's, not a weaker one — no token this driver mints can evict anybody.
 *
 * ROOMS. Agora has no server-side "create room": a channel exists the moment someone joins it, so `createRoom()`
 * is a documented no-op that only reports what the browser must be handed. `closeRoom()`/`revoke()` use the
 * documented **Kick-User** ("banning rule") REST endpoint, `POST {base}/dev/v1/kicking-rule`, authenticated with
 * the Agora account's RESTful Customer ID/Secret over HTTP Basic — a platform credential, not a clinic one, so it
 * comes from config rather than from tenant settings. Without it the driver logs and does nothing rather than
 * throwing on the doctor's "end call": the 15-minute token TTL is then the revocation story, as it is on Jitsi.
 *
 * WEBHOOKS. Agora's Notification Centre is a separate product with its own per-project signing secret and no
 * credential in the six `telemedicine.*` settings keys, so `verifyWebhook()` returns null and the module falls
 * back to its own client-reported join/leave beacons — the same position as self-hosted Jitsi.
 */
final class AgoraProvider implements VideoProvider
{
    /** Agora's public RESTful base. A deployment behind the mainland endpoint overrides it with `telemedicine.host`. */
    public const DEFAULT_REST_BASE = 'https://api.agora.io';

    public function __construct(
        private readonly ProviderCredentials $credentials,
        private readonly HttpFactory $http,
        private readonly int $timeout = 10,
        private readonly string $restCustomerId = '',
        private readonly string $restCustomerSecret = '',
    ) {}

    public function key(): TelemedicineProvider
    {
        return TelemedicineProvider::Agora;
    }

    /**
     * Honest on purpose: `ProviderCredentials::isComplete()` also demands a host, and Agora has none — the Web
     * SDK is handed an App ID, not a URL. What Agora does demand is that both ids are 32 hex characters.
     */
    public function isConfigured(): bool
    {
        return AgoraAccessToken::isCredential($this->credentials->apiKey)
            && AgoraAccessToken::isCredential($this->credentials->apiSecret);
    }

    /**
     * A NO-OP by design. An Agora channel is created implicitly by the first participant to join it; there is no
     * endpoint to call and nothing to be idempotent about. The `ProviderRoom` still comes back filled in so the
     * caller stores the same shape it stores for LiveKit: `url` carries the App ID, because that — not a
     * websocket URL — is what the Agora Web SDK needs to reach the service (it is public; the token is the
     * credential). There is no `sid` until a channel actually exists.
     */
    public function createRoom(RoomSpec $spec): ProviderRoom
    {
        return new ProviderRoom(roomName: $spec->roomName, sid: null, url: $this->credentials->apiKey);
    }

    public function mintToken(TokenRequest $request): JoinToken
    {
        $now = CarbonImmutable::now();
        $expiresAt = $now->addSeconds($request->ttlSeconds);
        $uid = self::uidFor($request->identity);

        // Every privilege expires with the token: Agora measures both as SECONDS FROM `issuedAt`, and a privilege
        // outliving its token would be a credential the 15-minute TTL no longer bounds.
        $services = [[
            'type' => AgoraAccessToken::SERVICE_RTC,
            'privileges' => [
                AgoraAccessToken::PRIVILEGE_JOIN_CHANNEL => $request->ttlSeconds,
                AgoraAccessToken::PRIVILEGE_PUBLISH_AUDIO_STREAM => $request->ttlSeconds,
                AgoraAccessToken::PRIVILEGE_PUBLISH_VIDEO_STREAM => $request->ttlSeconds,
                AgoraAccessToken::PRIVILEGE_PUBLISH_DATA_STREAM => $request->ttlSeconds,
            ],
            'channel' => $request->roomName,
            'uid' => (string) $uid,
        ]];

        if ($request->canRecord) {
            $services[] = [
                'type' => AgoraAccessToken::SERVICE_STREAMING,
                'privileges' => [
                    AgoraAccessToken::PRIVILEGE_PUBLISH_MIX_STREAM => $request->ttlSeconds,
                    AgoraAccessToken::PRIVILEGE_PUBLISH_RAW_STREAM => $request->ttlSeconds,
                ],
                'channel' => $request->roomName,
                'uid' => (string) $uid,
            ];
        }

        $token = AgoraAccessToken::build(
            appId: $this->credentials->apiKey,
            appCertificate: $this->credentials->apiSecret,
            services: $services,
            expireSeconds: $request->ttlSeconds,
            issuedAt: $now->getTimestamp(),
            salt: AgoraAccessToken::salt(),
        );

        return new JoinToken(
            provider: $this->key(),
            roomName: $request->roomName,
            role: $request->role,
            identity: $request->identity,
            token: $token,
            expiresAt: $expiresAt,
            serverUrl: $this->credentials->apiKey,          // the Web SDK takes an App ID where LiveKit takes a URL
            uid: $uid,
        );
    }

    /** Ban ONE participant from the channel. The identity is the same one the token was minted for. */
    public function revoke(string $roomName, string $identity): void
    {
        $this->kick('revoke', $roomName, self::uidFor($identity));
    }

    /**
     * Agora cannot delete a channel — it disappears when the last participant leaves. Closing therefore means
     * "nobody may join this channel again", which is a banning rule with uid 0: every user of the channel.
     */
    public function closeRoom(string $roomName): void
    {
        $this->kick('closeRoom', $roomName, 0);
    }

    public function verifyWebhook(string $payload, array $headers): ?ProviderWebhookEvent
    {
        return null;
    }

    /**
     * A stable, non-zero uint32 derived from the module's own opaque identity (`{role}-{24 hex}`), because Agora
     * addresses participants by number: the token is bound to it and the banning endpoint takes it, so the same
     * identity always kicks the participant it minted. 0 is never produced — Agora reads it as "every user".
     */
    public static function uidFor(string $identity): int
    {
        return (crc32($identity) % 2_147_483_646) + 1;
    }

    /** POST {base}/dev/v1/kicking-rule — the documented Kick-User endpoint. */
    private function kick(string $operation, string $roomName, int $uid): void
    {
        if (! $this->isConfigured() || $this->restCustomerId === '' || $this->restCustomerSecret === '') {
            // No account-level RESTful credential: the short token TTL is the revocation story (see Jitsi).
            Log::channel('clinical')->info('telemedicine.agora.kick_skipped', ['operation' => $operation, 'room' => $roomName, 'uid' => $uid]);

            return;
        }

        $response = $this->http
            ->timeout($this->timeout)
            ->withBasicAuth($this->restCustomerId, $this->restCustomerSecret)
            ->acceptJson()
            ->asJson()
            ->post($this->restBase().'/dev/v1/kicking-rule', [
                'appid' => $this->credentials->apiKey,
                'cname' => $roomName,
                'uid' => $uid,
                'ip' => '',
                // Minutes. Bounded by the clinic's own call cap: a rule that outlives the longest possible
                // consultation is a permanent ban left behind on the platform's Agora account.
                'time' => max(1, min(1440, $this->credentials->maxMinutes)),
                'privileges' => ['join_channel'],
            ]);

        if ($response->failed()) {
            throw new VideoProviderFailed('agora', $operation, self::reason($response));
        }
    }

    private function restBase(): string
    {
        $host = trim($this->credentials->host);

        if ($host === '') {
            return self::DEFAULT_REST_BASE;
        }

        return rtrim(str_starts_with($host, 'http') ? $host : 'https://'.$host, '/');
    }

    private static function reason(Response $response): string
    {
        $message = $response->json('message') ?? $response->json('reason');

        return is_string($message) && $message !== '' ? $message : 'HTTP '.$response->status();
    }
}
