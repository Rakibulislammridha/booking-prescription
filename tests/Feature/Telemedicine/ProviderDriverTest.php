<?php

declare(strict_types=1);

namespace Tests\Feature\Telemedicine;

use App\Domain\Clinic\Services\Settings;
use App\Domain\Shared\Actor;
use App\Domain\Telemedicine\Actions\JoinCall;
use App\Domain\Telemedicine\Data\ProviderWebhookEvent;
use App\Domain\Telemedicine\Data\RoomSpec;
use App\Domain\Telemedicine\Enums\ParticipantRole;
use App\Domain\Telemedicine\Enums\TelemedicineProvider;
use App\Domain\Telemedicine\Exceptions\VideoProviderFailed;
use App\Domain\Telemedicine\Providers\JitsiProvider;
use App\Domain\Telemedicine\Providers\LiveKitProvider;
use App\Domain\Telemedicine\Providers\NullVideoProvider;
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
