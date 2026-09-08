<?php

declare(strict_types=1);

namespace Tests\Unit\Telemedicine;

use App\Domain\Telemedicine\Data\TokenRequest;
use App\Domain\Telemedicine\Enums\ParticipantRole;
use App\Domain\Telemedicine\Enums\TelemedicineProvider;
use App\Domain\Telemedicine\Providers\JitsiProvider;
use App\Domain\Telemedicine\Providers\LiveKitProvider;
use App\Domain\Telemedicine\Providers\NullVideoProvider;
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

    public function test_identities_are_role_prefixed_so_a_provider_callback_can_be_attributed(): void
    {
        $this->assertSame('doctor-xyz', ParticipantRole::Doctor->identityFor('xyz'));
        $this->assertSame('patient-xyz', ParticipantRole::Patient->identityFor('xyz'));
        $this->assertTrue(ParticipantRole::Doctor->mayRecord());
        $this->assertFalse(ParticipantRole::Patient->mayRecord());
    }
}
