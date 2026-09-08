<?php

declare(strict_types=1);

namespace App\Domain\Telemedicine\Providers;

use App\Domain\Telemedicine\Contracts\VideoProvider;
use App\Domain\Telemedicine\Data\JoinToken;
use App\Domain\Telemedicine\Data\ProviderRoom;
use App\Domain\Telemedicine\Data\ProviderWebhookEvent;
use App\Domain\Telemedicine\Data\RoomSpec;
use App\Domain\Telemedicine\Data\TokenRequest;
use App\Domain\Telemedicine\Enums\ParticipantRole;
use App\Domain\Telemedicine\Enums\TelemedicineProvider;
use App\Domain\Telemedicine\Exceptions\VideoProviderFailed;
use App\Domain\Telemedicine\Services\Jwt;
use App\Domain\Telemedicine\Services\ProviderCredentials;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;

/**
 * LiveKit (https://docs.livekit.io) — the concrete driver.
 *
 * TOKENS are HS256 JWTs the SDK presents to the SFU: `iss` = API key, `sub` = identity, and a `video` grant
 * object that IS the authorisation. Everything the module cares about is in that object:
 *
 *     roomJoin      may join at all              always true (an authorised request got this far)
 *     room          WHICH room                   the one room; a token is never valid for another consultation
 *     canPublish    may send camera/mic          true for both roles — a consultation needs two faces
 *     canSubscribe  may receive the other side   true for both roles
 *     roomRecord    may start a recording        DOCTOR ONLY, and only when the clinic enabled recording
 *     roomAdmin     may mute/remove others       DOCTOR ONLY — the patient cannot evict the doctor
 *
 * REST is LiveKit's Twirp surface (`POST {host}/twirp/livekit.RoomService/{Method}`, JSON in, JSON out),
 * authenticated with a short admin token carrying `roomCreate`/`roomAdmin`.
 *
 * WEBHOOKS arrive with an `Authorization` JWT signed with the same secret whose `sha256` claim is the base64
 * digest of the raw body — so a replayed body with a different payload fails verification.
 */
final class LiveKitProvider implements VideoProvider
{
    public function __construct(
        private readonly ProviderCredentials $credentials,
        private readonly HttpFactory $http,
        private readonly int $timeout = 10,
    ) {}

    public function key(): TelemedicineProvider
    {
        return TelemedicineProvider::Livekit;
    }

    public function isConfigured(): bool
    {
        return $this->credentials->isComplete();
    }

    public function createRoom(RoomSpec $spec): ProviderRoom
    {
        $body = $this->call('CreateRoom', [
            'name' => $spec->roomName,
            'empty_timeout' => $spec->emptyTimeoutSeconds,
            'max_participants' => $spec->maxParticipants,
            'departure_timeout' => 60,
        ]);

        return new ProviderRoom(
            roomName: is_string($body['name'] ?? null) ? $body['name'] : $spec->roomName,
            sid: is_string($body['sid'] ?? null) ? $body['sid'] : null,
            url: $this->wsUrl(),
        );
    }

    public function mintToken(TokenRequest $request): JoinToken
    {
        $now = CarbonImmutable::now();
        $expiresAt = $now->addSeconds($request->ttlSeconds);

        $token = Jwt::encode([
            'iss' => $this->credentials->apiKey,
            'sub' => $request->identity,
            'name' => $request->displayName,
            'nbf' => $now->getTimestamp() - 5,
            'exp' => $expiresAt->getTimestamp(),
            'metadata' => (string) json_encode(['role' => $request->role->value], JSON_THROW_ON_ERROR),
            'video' => [
                'room' => $request->roomName,
                'roomJoin' => true,
                'canPublish' => $request->canPublish,
                'canSubscribe' => $request->canSubscribe,
                'canPublishData' => $request->canPublishData,
                'roomRecord' => $request->canRecord,
                'roomAdmin' => $request->isModerator,
                'roomCreate' => false,
                'roomList' => false,
            ],
        ], $this->credentials->apiSecret);

        return new JoinToken(
            provider: $this->key(),
            roomName: $request->roomName,
            role: $request->role,
            identity: $request->identity,
            token: $token,
            expiresAt: $expiresAt,
            serverUrl: $this->wsUrl(),
        );
    }

    public function revoke(string $roomName, string $identity): void
    {
        $this->call('RemoveParticipant', ['room' => $roomName, 'identity' => $identity]);
    }

    public function closeRoom(string $roomName): void
    {
        $this->call('DeleteRoom', ['room' => $roomName]);
    }

    public function verifyWebhook(string $payload, array $headers): ?ProviderWebhookEvent
    {
        $authorization = $headers['authorization'] ?? $headers['Authorization'] ?? null;

        if (! is_string($authorization) || $authorization === '' || ! $this->isConfigured()) {
            return null;
        }

        $claims = Jwt::decode(trim(str_ireplace('Bearer ', '', $authorization)), $this->credentials->apiSecret, leewaySeconds: 30);
        $digest = base64_encode(hash('sha256', $payload, true));

        if ($claims === null || ! is_string($claims['sha256'] ?? null) || ! hash_equals($claims['sha256'], $digest)) {
            return null;
        }

        /** @var array<string, mixed>|null $decoded */
        $decoded = json_decode($payload, true);

        if (! is_array($decoded) || ! is_string($decoded['event'] ?? null)) {
            return null;
        }

        /** @var array<string, mixed> $room */
        $room = is_array($decoded['room'] ?? null) ? $decoded['room'] : [];
        /** @var array<string, mixed> $participant */
        $participant = is_array($decoded['participant'] ?? null) ? $decoded['participant'] : [];
        $identity = is_string($participant['identity'] ?? null) ? $participant['identity'] : null;

        $type = match ($decoded['event']) {
            'participant_joined' => ProviderWebhookEvent::PARTICIPANT_JOINED,
            'participant_left' => ProviderWebhookEvent::PARTICIPANT_LEFT,
            'room_finished' => ProviderWebhookEvent::ROOM_FINISHED,
            'recording_started', 'egress_started' => ProviderWebhookEvent::RECORDING_STARTED,
            'recording_finished', 'egress_ended' => ProviderWebhookEvent::RECORDING_FINISHED,
            default => null,
        };

        if ($type === null || ! is_string($room['name'] ?? null)) {
            return null;
        }

        $createdAt = is_numeric($decoded['createdAt'] ?? null) ? CarbonImmutable::createFromTimestamp((int) $decoded['createdAt']) : CarbonImmutable::now();

        return new ProviderWebhookEvent(
            type: $type,
            roomName: (string) $room['name'],
            role: self::roleOf($identity),
            identity: $identity,
            occurredAt: $createdAt,
            sid: is_string($room['sid'] ?? null) ? $room['sid'] : null,
            recordingPath: self::recordingPath($decoded),
            raw: $decoded,
        );
    }

    /** Identities are minted as `{role}-{publicId}` (ParticipantRole::identityFor), so the role reads back off them. */
    private static function roleOf(?string $identity): ?ParticipantRole
    {
        if ($identity === null) {
            return null;
        }

        foreach (ParticipantRole::cases() as $role) {
            if (str_starts_with($identity, $role->value.'-')) {
                return $role;
            }
        }

        return null;
    }

    /** @param  array<string, mixed>  $decoded */
    private static function recordingPath(array $decoded): ?string
    {
        $egress = is_array($decoded['egressInfo'] ?? null) ? $decoded['egressInfo'] : [];
        $files = is_array($egress['fileResults'] ?? null) ? $egress['fileResults'] : [];
        $first = is_array($files[0] ?? null) ? $files[0] : [];

        return is_string($first['filename'] ?? null) ? $first['filename'] : null;
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private function call(string $method, array $body): array
    {
        if (! $this->isConfigured()) {
            throw new VideoProviderFailed('livekit', $method, 'not configured');
        }

        $response = $this->http
            ->timeout($this->timeout)
            ->withToken($this->adminToken())
            ->acceptJson()
            ->asJson()
            ->post($this->httpUrl().'/twirp/livekit.RoomService/'.$method, $body);

        if ($response->failed()) {
            throw new VideoProviderFailed('livekit', $method, self::reason($response));
        }

        /** @var array<string, mixed> $decoded */
        $decoded = (array) $response->json();

        return $decoded;
    }

    private function adminToken(): string
    {
        $now = CarbonImmutable::now();

        return Jwt::encode([
            'iss' => $this->credentials->apiKey,
            'sub' => 'bp-server',
            'nbf' => $now->getTimestamp() - 5,
            'exp' => $now->addSeconds(60)->getTimestamp(),
            'video' => ['roomCreate' => true, 'roomAdmin' => true, 'roomList' => true],
        ], $this->credentials->apiSecret);
    }

    private static function reason(Response $response): string
    {
        $message = $response->json('msg') ?? $response->json('message');

        return is_string($message) && $message !== '' ? $message : 'HTTP '.$response->status();
    }

    /** LiveKit is configured with one host; the SDK wants `wss://`, the REST API wants `https://`. */
    private function httpUrl(): string
    {
        return rtrim(str_replace(['wss://', 'ws://'], ['https://', 'http://'], $this->credentials->host), '/');
    }

    private function wsUrl(): string
    {
        return rtrim(str_replace(['https://', 'http://'], ['wss://', 'ws://'], $this->credentials->host), '/');
    }
}
