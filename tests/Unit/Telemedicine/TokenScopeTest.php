<?php

declare(strict_types=1);

namespace Tests\Unit\Telemedicine;

use App\Domain\Telemedicine\Data\RoomSpec;
use App\Domain\Telemedicine\Data\TokenRequest;
use App\Domain\Telemedicine\Enums\ParticipantRole;
use App\Domain\Telemedicine\Enums\TelemedicineProvider;
use App\Domain\Telemedicine\Providers\AgoraProvider;
use App\Domain\Telemedicine\Providers\JitsiProvider;
use App\Domain\Telemedicine\Providers\LiveKitProvider;
use App\Domain\Telemedicine\Providers\NullVideoProvider;
use App\Domain\Telemedicine\Services\AgoraAccessToken;
use App\Domain\Telemedicine\Services\Jwt;
use App\Domain\Telemedicine\Services\ProviderCredentials;
use Illuminate\Http\Client\Factory as HttpFactory;
use PHPUnit\Framework\TestCase;

/**
 * Token SCOPING — the security surface of this module. A join token is a bearer credential for a live
 * consultation, so what it grants must be a function of the role and of nothing a caller can influence.
 */
final class TokenScopeTest extends TestCase
{
    private const SECRET = 'livekit-secret';

    private const ROOM = 't9001-01jabcdefghjkmnpqrstvwxyz0';

    /** Agora ids are 32 hex characters; anything else is not a credential (AgoraAccessToken::isCredential). */
    private const AGORA_APP_ID = '970ca35de60c44645bbae8a215061b33';

    private const AGORA_CERTIFICATE = '5cfd2fd1755d40ecb72977518be15d3b';

    private function liveKit(): LiveKitProvider
    {
        return new LiveKitProvider(
            new ProviderCredentials(TelemedicineProvider::Livekit, 'wss://livekit.example.test', 'API-KEY', self::SECRET),
            new HttpFactory,
        );
    }

    private function request(ParticipantRole $role, int $ttl = 900, bool $recordingAllowed = true): TokenRequest
    {
        return TokenRequest::forRole(self::ROOM, $role, $role->identityFor('abc123'), 'Dr Rahman', $ttl, $recordingAllowed);
    }

    public function test_a_doctor_token_carries_the_room_the_role_and_the_moderator_grants(): void
    {
        $token = $this->liveKit()->mintToken($this->request(ParticipantRole::Doctor));
        $claims = Jwt::decode($token->token, self::SECRET);

        $this->assertIsArray($claims);
        $this->assertSame('API-KEY', $claims['iss']);
        $this->assertSame('doctor-abc123', $claims['sub']);
        $this->assertSame(self::ROOM, $claims['video']['room']);
        $this->assertTrue($claims['video']['roomJoin']);
        $this->assertTrue($claims['video']['canPublish']);
        $this->assertTrue($claims['video']['canSubscribe']);
        $this->assertTrue($claims['video']['roomAdmin']);
        $this->assertTrue($claims['video']['roomRecord']);
        $this->assertSame('{"role":"doctor"}', $claims['metadata']);
        $this->assertSame('wss://livekit.example.test', $token->serverUrl);
    }

    public function test_a_patient_token_can_never_grant_doctor_privileges(): void
    {
        $token = $this->liveKit()->mintToken($this->request(ParticipantRole::Patient));
        $claims = Jwt::decode($token->token, self::SECRET);

        $this->assertIsArray($claims);
        $this->assertSame('patient-abc123', $claims['sub']);
        $this->assertTrue($claims['video']['canPublish'], 'a patient must still be visible and audible');
        $this->assertTrue($claims['video']['canSubscribe']);
        $this->assertFalse($claims['video']['roomAdmin'], 'a patient must not be able to remove the doctor');
        $this->assertFalse($claims['video']['roomRecord'], 'a patient must not be able to record the consultation');
        $this->assertFalse($claims['video']['roomCreate']);
        $this->assertFalse($claims['video']['roomList']);
    }

    public function test_recording_is_refused_to_a_patient_even_when_the_clinic_allows_recording(): void
    {
        $patient = TokenRequest::forRole(self::ROOM, ParticipantRole::Patient, 'patient-x', 'Rahima', 900, recordingAllowed: true);

        $this->assertFalse($patient->canRecord);
        $this->assertFalse($patient->isModerator);
    }

    public function test_recording_is_refused_to_the_doctor_when_the_clinic_disallows_it(): void
    {
        $doctor = TokenRequest::forRole(self::ROOM, ParticipantRole::Doctor, 'doctor-x', 'Dr Rahman', 900, recordingAllowed: false);

        $this->assertFalse($doctor->canRecord);
        $this->assertTrue($doctor->isModerator);
    }

    public function test_the_ttl_is_short_and_bounded_below(): void
    {
        $before = time();
        $token = $this->liveKit()->mintToken($this->request(ParticipantRole::Patient, ttl: 900));
        $claims = Jwt::decode($token->token, self::SECRET);

        $this->assertIsArray($claims);
        $this->assertEqualsWithDelta($before + 900, $claims['exp'], 3);
        $this->assertLessThanOrEqual($before, $claims['nbf']);
        $this->assertSame(900, $token->expiresAt->getTimestamp() - $before, 'the DTO agrees with the claim');

        // A caller asking for a one-second token gets the floor, not a token that expires before it arrives.
        $this->assertSame(30, TokenRequest::forRole(self::ROOM, ParticipantRole::Patient, 'p', 'p', 1)->ttlSeconds);
    }

    public function test_a_token_is_valid_for_exactly_one_room(): void
    {
        $claims = Jwt::decode($this->liveKit()->mintToken($this->request(ParticipantRole::Patient))->token, self::SECRET);

        $this->assertIsArray($claims);
        $this->assertSame(self::ROOM, $claims['video']['room']);
        $this->assertNotSame('*', $claims['video']['room']);
    }

    public function test_the_jitsi_driver_scopes_by_moderator_and_features(): void
    {
        $jitsi = new JitsiProvider(new ProviderCredentials(TelemedicineProvider::Jitsi, 'meet.example.test', 'bp-app', self::SECRET));

        $doctor = Jwt::decode($jitsi->mintToken($this->request(ParticipantRole::Doctor))->token, self::SECRET);
        $patient = Jwt::decode($jitsi->mintToken($this->request(ParticipantRole::Patient))->token, self::SECRET);

        $this->assertIsArray($doctor);
        $this->assertIsArray($patient);
        $this->assertSame('jitsi', $doctor['aud']);
        $this->assertSame(self::ROOM, $doctor['room']);
        $this->assertSame('true', $doctor['context']['user']['moderator']);
        $this->assertSame('false', $patient['context']['user']['moderator']);
        $this->assertTrue($doctor['context']['features']['recording']);
        $this->assertFalse($patient['context']['features']['recording']);
    }

    public function test_the_jitsi_join_url_carries_the_token_and_the_room(): void
    {
        $jitsi = new JitsiProvider(new ProviderCredentials(TelemedicineProvider::Jitsi, 'https://meet.example.test/', 'bp-app', self::SECRET));
        $token = $jitsi->mintToken($this->request(ParticipantRole::Patient));

        $this->assertNotNull($token->joinUrl);
        $this->assertStringStartsWith('https://meet.example.test/'.self::ROOM.'?jwt=', (string) $token->joinUrl);
    }

    public function test_the_null_driver_mints_a_real_scoped_token_and_no_server_url(): void
    {
        $null = new NullVideoProvider('app-key');

        $doctor = $null->mintToken($this->request(ParticipantRole::Doctor));
        $patient = $null->mintToken($this->request(ParticipantRole::Patient));

        $this->assertSame(TelemedicineProvider::Jitsi, $null->key(), 'the CHECK has no null member; the log driver stands in for jitsi');
        $this->assertNull($doctor->serverUrl, 'no server ⇒ the client runs local preview');
        $this->assertTrue(Jwt::decode($doctor->token, 'app-key')['video']['roomAdmin']);
        $this->assertFalse(Jwt::decode($patient->token, 'app-key')['video']['roomAdmin']);
    }

    /**
     * Agora, whose credential is an AccessToken2 binary packing rather than a JWT — so the scope is asserted by
     * DECODING the token back into its services and privilege maps, not by reading claims.
     */
    private function agora(): AgoraProvider
    {
        return new AgoraProvider(
            new ProviderCredentials(TelemedicineProvider::Agora, '', self::AGORA_APP_ID, self::AGORA_CERTIFICATE),
            new HttpFactory,
        );
    }

    public function test_the_agora_driver_scopes_by_service_privilege_channel_and_uid(): void
    {
        $token = $this->agora()->mintToken($this->request(ParticipantRole::Doctor));
        $decoded = AgoraAccessToken::parse($token->token);

        $this->assertNotNull($decoded);
        $this->assertTrue(AgoraAccessToken::verify($token->token, self::AGORA_CERTIFICATE), 'signed with the App Certificate');
        $this->assertSame(self::AGORA_APP_ID, $decoded->appId);
        $this->assertSame(self::AGORA_APP_ID, $token->serverUrl, 'the Web SDK takes an App ID where LiveKit takes a URL');
        $this->assertSame(900, $decoded->expireSeconds, 'a duration from the issue time, not a timestamp');

        $rtc = $decoded->service(AgoraAccessToken::SERVICE_RTC);
        $this->assertNotNull($rtc);
        $this->assertSame(self::ROOM, $rtc['channel'], 'one token, one channel');
        $this->assertSame((string) AgoraProvider::uidFor('doctor-abc123'), $rtc['uid']);
        $this->assertSame(AgoraProvider::uidFor('doctor-abc123'), $token->uid);
        $this->assertSame([1 => 900, 2 => 900, 3 => 900, 4 => 900], $rtc['privileges'], 'join + publish audio, video and data, each expiring with the token');

        // Recording was allowed for this request, so the doctor also gets the streaming service.
        $this->assertTrue($decoded->hasService(AgoraAccessToken::SERVICE_STREAMING));
        $this->assertSame([1 => 900, 2 => 900], $decoded->privileges(AgoraAccessToken::SERVICE_STREAMING));
    }

    public function test_an_agora_patient_token_can_never_grant_doctor_privileges(): void
    {
        // recordingAllowed: true — the clinic HAS enabled recording, and it still must not reach the patient.
        $token = $this->agora()->mintToken($this->request(ParticipantRole::Patient, recordingAllowed: true));
        $decoded = AgoraAccessToken::parse($token->token);

        $this->assertNotNull($decoded);
        $this->assertCount(1, $decoded->services, 'a patient token carries the RTC service and nothing else');
        $this->assertFalse($decoded->hasService(AgoraAccessToken::SERVICE_STREAMING), 'a patient must not be able to push the consultation out of the channel');
        $this->assertSame([], $decoded->privileges(AgoraAccessToken::SERVICE_STREAMING));

        $rtc = $decoded->service(AgoraAccessToken::SERVICE_RTC);
        $this->assertNotNull($rtc);
        $this->assertTrue($decoded->grants(AgoraAccessToken::SERVICE_RTC, AgoraAccessToken::PRIVILEGE_PUBLISH_AUDIO_STREAM), 'a patient must still be audible');
        $this->assertTrue($decoded->grants(AgoraAccessToken::SERVICE_RTC, AgoraAccessToken::PRIVILEGE_PUBLISH_VIDEO_STREAM), 'a patient must still be visible');
        $this->assertSame(self::ROOM, $rtc['channel']);
        $this->assertNotSame((string) AgoraProvider::uidFor('doctor-abc123'), $rtc['uid'], "a patient's token is not the doctor's token");

        // There is no roomAdmin equivalent to assert the absence of: Agora has no moderation privilege at all,
        // so NO token this driver mints — patient or doctor — can evict anyone. Kicking is an account-level
        // REST call the server makes, which is a stronger guarantee than LiveKit's roomAdmin flag, not a weaker one.
        $this->assertSame([AgoraAccessToken::SERVICE_RTC], array_column($decoded->services, 'type'));
    }

    public function test_agora_recording_is_refused_to_the_doctor_when_the_clinic_disallows_it(): void
    {
        $token = $this->agora()->mintToken($this->request(ParticipantRole::Doctor, recordingAllowed: false));
        $decoded = AgoraAccessToken::parse($token->token);

        $this->assertNotNull($decoded);
        $this->assertFalse($decoded->hasService(AgoraAccessToken::SERVICE_STREAMING));
        $this->assertTrue($decoded->grants(AgoraAccessToken::SERVICE_RTC, AgoraAccessToken::PRIVILEGE_JOIN_CHANNEL), 'the consultation still happens');
    }

    public function test_the_agora_driver_is_unconfigured_unless_both_ids_are_agora_ids(): void
    {
        $this->assertTrue($this->agora()->isConfigured());

        foreach ([['', ''], ['half', self::AGORA_CERTIFICATE], [self::AGORA_APP_ID, 'not-a-certificate'], [self::AGORA_APP_ID, self::AGORA_CERTIFICATE.'0']] as [$key, $secret]) {
            $driver = new AgoraProvider(new ProviderCredentials(TelemedicineProvider::Agora, '', $key, $secret), new HttpFactory);
            $this->assertFalse($driver->isConfigured(), 'a half-pasted credential must fall back to the null driver');
        }
    }

    public function test_agora_creates_no_room_because_a_channel_exists_when_someone_joins(): void
    {
        $room = $this->agora()->createRoom(new RoomSpec(self::ROOM));

        $this->assertSame(self::ROOM, $room->roomName);
        $this->assertNull($room->sid, 'there is no channel until a participant joins one');
        $this->assertSame(self::AGORA_APP_ID, $room->url);
    }

    public function test_identities_are_role_prefixed_so_a_provider_callback_can_be_attributed(): void
    {
        $this->assertSame('doctor-xyz', ParticipantRole::Doctor->identityFor('xyz'));
        $this->assertSame('patient-xyz', ParticipantRole::Patient->identityFor('xyz'));
        $this->assertTrue(ParticipantRole::Doctor->mayRecord());
        $this->assertFalse(ParticipantRole::Patient->mayRecord());
    }
}
