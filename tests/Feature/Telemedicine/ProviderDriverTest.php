<?php

declare(strict_types=1);

namespace Tests\Feature\Telemedicine;

use App\Domain\Clinic\Services\Settings;
use App\Domain\Shared\Actor;
use App\Domain\Telemedicine\Actions\JoinCall;
use App\Domain\Telemedicine\Data\ProviderWebhookEvent;
use App\Domain\Telemedicine\Data\RoomSpec;
use App\Domain\Telemedicine\Data\TokenRequest;
use App\Domain\Telemedicine\Enums\ParticipantRole;
use App\Domain\Telemedicine\Enums\TelemedicineProvider;
use App\Domain\Telemedicine\Exceptions\VideoProviderFailed;
use App\Domain\Telemedicine\Providers\AgoraProvider;
use App\Domain\Telemedicine\Providers\JitsiProvider;
use App\Domain\Telemedicine\Providers\LiveKitProvider;
use App\Domain\Telemedicine\Providers\NullVideoProvider;
use App\Domain\Telemedicine\Services\AgoraAccessToken;
use App\Domain\Telemedicine\Services\Jwt;
use App\Domain\Telemedicine\Services\ProviderCredentials;
use App\Domain\Telemedicine\Services\TelemedicineSettings;
use App\Domain\Telemedicine\Services\VideoProviderManager;
use App\Models\Tenant\TelemedicineSession;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Telemedicine\Concerns\TelemedicineFixtures;
use Tests\TestCase;

/**
 * Driver behaviour against a FAKED HTTP client. Nothing in the suite may reach a real video service, so the
 * LiveKit driver is exercised through `Http::fake()` — request shape, auth header, response mapping, and what it
 * does when the provider says no.
 */
final class ProviderDriverTest extends TestCase
{
    use TelemedicineFixtures;

    private const SECRET = 'livekit-secret';

    protected function setUp(): void
    {
        parent::setUp();
        $this->asTenant('a');
        $this->enableTelemedicine();
    }

    private function liveKit(): LiveKitProvider
    {
        return new LiveKitProvider(
            new ProviderCredentials(TelemedicineProvider::Livekit, 'wss://livekit.example.test', 'API-KEY', self::SECRET),
            app(HttpFactory::class),
        );
    }

    public function test_create_room_posts_the_documented_twirp_shape_with_an_admin_token(): void
    {
        Http::fake(['livekit.example.test/*' => Http::response(['sid' => 'RM_abc', 'name' => 'room-1'])]);

        $room = $this->liveKit()->createRoom(new RoomSpec('room-1', maxParticipants: 4, emptyTimeoutSeconds: 900, maxMinutes: 45));

        $this->assertSame('RM_abc', $room->sid);
        $this->assertSame('wss://livekit.example.test', $room->url);

        Http::assertSent(function ($request): bool {
            $this->assertSame('https://livekit.example.test/twirp/livekit.RoomService/CreateRoom', $request->url());
            $this->assertSame('room-1', $request['name']);
            $this->assertSame(900, $request['empty_timeout']);
            $this->assertSame(4, $request['max_participants']);

            $claims = Jwt::decode(str_replace('Bearer ', '', $request->header('Authorization')[0]), self::SECRET);
            $this->assertIsArray($claims);
            $this->assertTrue($claims['video']['roomCreate'], 'the SERVER token creates rooms; a participant token never does');
            $this->assertTrue($claims['video']['roomAdmin']);

            return true;
        });
    }

    public function test_a_provider_error_becomes_a_domain_failure_that_leaks_no_credentials(): void
    {
        Http::fake(['livekit.example.test/*' => Http::response(['msg' => 'invalid API key'], 401)]);

        try {
            $this->liveKit()->createRoom(new RoomSpec('room-1'));
            $this->fail('expected VideoProviderFailed');
        } catch (VideoProviderFailed $e) {
            $this->assertSame('telemedicine.provider_failed', $e->code());
            $this->assertSame(502, $e->status());
            $this->assertStringContainsString('invalid API key', $e->getMessage());
            $this->assertStringNotContainsString(self::SECRET, $e->getMessage());
            $this->assertStringNotContainsString('API-KEY', $e->getMessage());
        }
    }

    public function test_a_transport_failure_is_also_a_domain_failure(): void
    {
        Http::fake(['livekit.example.test/*' => Http::response('', 500)]);

        $this->expectException(VideoProviderFailed::class);
        $this->liveKit()->closeRoom('room-1');
    }

    public function test_revoke_and_close_hit_the_documented_endpoints(): void
    {
        Http::fake(['livekit.example.test/*' => Http::response([])]);

        $this->liveKit()->revoke('room-1', 'patient-abc');
        $this->liveKit()->closeRoom('room-1');

        Http::assertSent(fn ($r) => str_ends_with($r->url(), 'RemoveParticipant') && $r['identity'] === 'patient-abc');
        Http::assertSent(fn ($r) => str_ends_with($r->url(), 'DeleteRoom') && $r['room'] === 'room-1');
    }

    public function test_a_livekit_webhook_verifies_the_body_digest_before_it_is_believed(): void
    {
        $payload = (string) json_encode([
            'event' => 'participant_joined',
            'room' => ['sid' => 'RM_1', 'name' => 't9001-room'],
            'participant' => ['identity' => 'patient-abc'],
            'createdAt' => time(),
        ]);
        $auth = Jwt::encode(['sha256' => base64_encode(hash('sha256', $payload, true)), 'exp' => time() + 60], self::SECRET);

        $event = $this->liveKit()->verifyWebhook($payload, ['authorization' => $auth]);

        $this->assertInstanceOf(ProviderWebhookEvent::class, $event);
        $this->assertSame(ProviderWebhookEvent::PARTICIPANT_JOINED, $event->type);
        $this->assertSame('t9001-room', $event->roomName);
        $this->assertSame(ParticipantRole::Patient, $event->role, 'the role reads back off the identity we minted');

        // Same signature, different body: refused.
        $this->assertNull($this->liveKit()->verifyWebhook($payload.' ', ['authorization' => $auth]));
        // Signature from another secret: refused.
        $this->assertNull($this->liveKit()->verifyWebhook($payload, ['authorization' => Jwt::encode(['sha256' => base64_encode(hash('sha256', $payload, true))], 'other')]));
        // No header at all: refused.
        $this->assertNull($this->liveKit()->verifyWebhook($payload, []));
    }

    public function test_an_unverifiable_webhook_is_401_and_an_unknown_room_is_202(): void
    {
        $this->postJson('/api/webhooks/telemedicine', ['event' => 'participant_joined'])
            ->assertStatus(401)
            ->assertJsonPath('code', 'telemedicine.webhook_unverified');
    }

    public function test_a_signed_provider_leave_closes_the_presence_the_browser_never_closed(): void
    {
        [, $doctor] = $this->actingAsTelemedicineDoctor();
        $booking = $this->bookTelemedicine($this->openSessionFor($doctor));
        $room = $this->roomFor($booking->appointment->id);
        $this->startCall($room);
        app(JoinCall::class)->handle($room->refresh(), ParticipantRole::Doctor, Actor::user(1), 'panel');

        $this->configureLiveKit();
        $payload = (string) json_encode(['event' => 'participant_left', 'room' => ['name' => $room->room_name], 'participant' => ['identity' => 'doctor-x'], 'createdAt' => time()]);

        $this->webhook($payload)->assertOk()->assertJsonPath('applied', true);

        $call = TelemedicineSession::query()->where('telemedicine_room_id', $room->id)->firstOrFail();
        $this->assertNotNull($call->participants[0]['left_at'], 'the provider is more trustworthy than a killed tab');
    }

    public function test_a_signed_webhook_for_an_unknown_room_is_accepted_and_dropped(): void
    {
        $this->configureLiveKit();
        $payload = (string) json_encode(['event' => 'participant_left', 'room' => ['name' => 't9001-00000000000000000000000000'], 'participant' => ['identity' => 'doctor-x']]);

        $this->webhook($payload)->assertStatus(202)->assertJsonPath('applied', false);
    }

    private function configureLiveKit(): void
    {
        config(['telemedicine.default' => 'livekit', 'telemedicine.providers.livekit' => ['host' => 'wss://livekit.example.test', 'key' => 'API-KEY', 'secret' => self::SECRET]]);
    }

    /**
     * Posts the RAW body the signature was computed over — a re-encoded body would not verify, which is the point.
     *
     * @return TestResponse<Response>
     */
    private function webhook(string $payload): TestResponse
    {
        $auth = Jwt::encode(['sha256' => base64_encode(hash('sha256', $payload, true)), 'exp' => time() + 60], self::SECRET);

        return $this->call('POST', '/api/webhooks/telemedicine', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_AUTHORIZATION' => $auth,
        ], $payload);
    }

    public function test_the_manager_falls_back_to_the_null_driver_without_credentials(): void
    {
        config(['telemedicine.default' => 'livekit', 'telemedicine.providers.livekit' => ['host' => '', 'key' => '', 'secret' => '']]);

        $this->assertInstanceOf(NullVideoProvider::class, app(VideoProviderManager::class)->driver());
    }

    public function test_the_manager_resolves_each_configured_driver(): void
    {
        config(['telemedicine.default' => 'livekit', 'telemedicine.providers.livekit' => ['host' => 'wss://lk.test', 'key' => 'k', 'secret' => 's']]);
        $this->assertInstanceOf(LiveKitProvider::class, app(VideoProviderManager::class)->driver());

        config(['telemedicine.default' => 'jitsi', 'telemedicine.providers.jitsi' => ['host' => 'meet.test', 'key' => 'k', 'secret' => 's']]);
        $this->assertInstanceOf(JitsiProvider::class, app(VideoProviderManager::class)->driver());

        config(['telemedicine.default' => 'null']);
        $this->assertInstanceOf(NullVideoProvider::class, app(VideoProviderManager::class)->driver());
    }

    // ---------------------------------------------------------------- Agora
    //
    // Agora has no room API and no participant API: a channel exists when someone joins it, and the only server
    // verb the module needs is the documented Kick-User ("banning rule") endpoint. It is authenticated with the
    // ACCOUNT's RESTful Customer ID/Secret over HTTP Basic — a platform credential, never a clinic's — which is
    // why the manager reads it from config while the App ID/Certificate come from the tenant settings path.

    private const AGORA_APP_ID = '970ca35de60c44645bbae8a215061b33';

    private const AGORA_CERTIFICATE = '5cfd2fd1755d40ecb72977518be15d3b';

    private function agora(string $customerId = 'customer-id', string $customerSecret = 'customer-secret'): AgoraProvider
    {
        return new AgoraProvider(
            new ProviderCredentials(TelemedicineProvider::Agora, '', self::AGORA_APP_ID, self::AGORA_CERTIFICATE, maxMinutes: 45),
            app(HttpFactory::class),
            10,
            $customerId,
            $customerSecret,
        );
    }

    public function test_agora_revoke_and_close_hit_the_documented_kick_endpoint(): void
    {
        Http::fake(['api.agora.io/*' => Http::response(['status' => 'success', 'id' => 41])]);

        $this->agora()->revoke('t9001-room', 'patient-abc');
        $this->agora()->closeRoom('t9001-room');

        Http::assertSent(function ($request): bool {
            if ($request['uid'] === 0) {
                return false;
            }

            $this->assertSame('https://api.agora.io/dev/v1/kicking-rule', $request->url());
            $this->assertSame(self::AGORA_APP_ID, $request['appid']);
            $this->assertSame('t9001-room', $request['cname']);
            $this->assertSame(AgoraProvider::uidFor('patient-abc'), $request['uid'], 'the same uid the token was minted for');
            $this->assertNotSame(0, $request['uid'], '0 would ban the whole channel');
            $this->assertSame(['join_channel'], $request['privileges']);
            $this->assertSame(45, $request['time'], 'minutes: bounded by the clinic call cap, never a permanent ban');
            $this->assertSame('Basic '.base64_encode('customer-id:customer-secret'), $request->header('Authorization')[0]);

            return true;
        });

        // Closing a room bans every user of the channel, because Agora cannot delete a channel that is still live.
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/dev/v1/kicking-rule') && $r['cname'] === 't9001-room' && $r['uid'] === 0);
    }

    public function test_an_agora_provider_error_becomes_a_domain_failure_that_leaks_no_credentials(): void
    {
        Http::fake(['api.agora.io/*' => Http::response(['message' => 'invalid customer id'], 401)]);

        try {
            $this->agora()->closeRoom('t9001-room');
            $this->fail('expected VideoProviderFailed');
        } catch (VideoProviderFailed $e) {
            $this->assertSame('telemedicine.provider_failed', $e->code());
            $this->assertSame(502, $e->status());
            $this->assertSame('agora', $e->driver);
            $this->assertSame('closeRoom', $e->operation);
            $this->assertStringContainsString('invalid customer id', $e->getMessage());
            $this->assertStringNotContainsString(self::AGORA_CERTIFICATE, $e->getMessage());
            $this->assertStringNotContainsString('customer-secret', $e->getMessage());
        }
    }

    public function test_agora_kicking_is_a_logged_no_op_without_the_account_rest_credential(): void
    {
        Http::fake();

        // A platform that configured tokens but not the RESTful credential must not blow up the doctor's "end
        // call": the 15-minute token TTL is then the revocation story, exactly as it is on Jitsi.
        $this->agora(customerId: '', customerSecret: '')->closeRoom('t9001-room');
        $this->agora(customerId: '', customerSecret: '')->revoke('t9001-room', 'doctor-abc');

        Http::assertNothingSent();
    }

    public function test_agora_mints_a_token_without_ever_calling_agora(): void
    {
        Http::fake();

        $token = $this->agora()->mintToken(TokenRequest::forRole('t9001-room', ParticipantRole::Doctor, 'doctor-abc', 'Dr Rahman', 900, recordingAllowed: true));

        $this->assertSame(TelemedicineProvider::Agora, $token->provider);
        $this->assertTrue(AgoraAccessToken::verify($token->token, self::AGORA_CERTIFICATE));
        Http::assertNothingSent();
    }

    public function test_the_manager_resolves_the_agora_driver_from_the_settings_path(): void
    {
        config([
            'telemedicine.default' => 'agora',
            'telemedicine.providers.agora' => ['host' => '', 'key' => self::AGORA_APP_ID, 'secret' => self::AGORA_CERTIFICATE, 'rest_key' => 'cid', 'rest_secret' => 'csecret'],
        ]);

        $driver = app(VideoProviderManager::class)->driver();

        $this->assertInstanceOf(AgoraProvider::class, $driver);
        $this->assertSame(TelemedicineProvider::Agora, $driver->key());

        // A clinic overriding only the App ID/Certificate rows still resolves Agora — the credential path is the
        // ordinary one; only the account-level RESTful secret comes from config.
        app(Settings::class)->set(TelemedicineSettings::KEY_API_KEY, self::AGORA_APP_ID);
        app(TelemedicineSettings::class)->storeSecret(self::AGORA_CERTIFICATE);
        $this->assertInstanceOf(AgoraProvider::class, app(VideoProviderManager::class)->driver());
    }

    public function test_the_manager_falls_back_to_the_null_driver_when_the_agora_credentials_are_not_agora_credentials(): void
    {
        foreach ([['', ''], ['not-an-app-id', self::AGORA_CERTIFICATE], [self::AGORA_APP_ID, 'nope']] as [$key, $secret]) {
            config(['telemedicine.default' => 'agora', 'telemedicine.providers.agora' => ['host' => '', 'key' => $key, 'secret' => $secret]]);

            $this->assertInstanceOf(NullVideoProvider::class, app(VideoProviderManager::class)->driver(), $key.'/'.$secret);
        }
    }

    public function test_tenant_settings_override_the_platform_configuration_and_the_secret_is_encrypted_at_rest(): void
    {
        config(['telemedicine.default' => 'null', 'telemedicine.providers.jitsi' => ['host' => 'platform.test', 'key' => 'platform-key', 'secret' => 'platform-secret']]);
        $settings = app(TelemedicineSettings::class);

        app(Settings::class)->set(TelemedicineSettings::KEY_PROVIDER, 'jitsi');
        app(Settings::class)->set(TelemedicineSettings::KEY_HOST, 'meet.clinic.test');
        app(Settings::class)->set(TelemedicineSettings::KEY_API_KEY, 'clinic-key');
        $settings->storeSecret('clinic-secret');

        $stored = (string) DB::table('settings')->where('key', TelemedicineSettings::KEY_API_SECRET)->value('value');
        $this->assertStringNotContainsString('clinic-secret', $stored, 'a video API secret does not sit in plain jsonb');
        $this->assertSame('clinic-secret', Crypt::decryptString(trim($stored, '"')));

        $credentials = app(TelemedicineSettings::class)->credentials();
        $this->assertSame('meet.clinic.test', $credentials->host);
        $this->assertSame('clinic-key', $credentials->apiKey);
        $this->assertSame('clinic-secret', $credentials->apiSecret);
        $this->assertInstanceOf(JitsiProvider::class, app(VideoProviderManager::class)->driver());
    }
}
