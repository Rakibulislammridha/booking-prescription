<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Domain\Notifications\Contracts\DriverFactory;
use App\Domain\Notifications\Data\GatewayConfig;
use App\Domain\Notifications\Data\OutboundMessage;
use App\Domain\Notifications\Drivers\Ivr\HttpIvrDriver;
use App\Domain\Notifications\Drivers\Push\WebPushDriver;
use App\Domain\Notifications\Drivers\Sms\GenericHttpSmsDriver;
use App\Domain\Notifications\Drivers\Sms\SslWirelessSmsDriver;
use App\Domain\Notifications\Drivers\WhatsApp\WhatsAppCloudDriver;
use App\Domain\Notifications\Enums\GatewayProvider;
use App\Domain\Notifications\Enums\NotificationChannel;
use App\Domain\Notifications\Enums\NotificationEvent;
use App\Domain\Notifications\Enums\NotificationLogStatus;
use App\Domain\Notifications\Services\SegmentCounter;
use App\Domain\Notifications\Services\VapidSigner;
use App\Domain\Notifications\Services\WebPushEncryptor;
use App\Domain\Notifications\Support\Ec;
use App\Models\Tenant\Patient;
use App\Models\Tenant\PushSubscription;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Notifications\Concerns\NotificationFixtures;
use Tests\TestCase;

/**
 * Every driver against a FAKED HTTP client — no test in this suite touches a real endpoint or carries a real
 * credential. Each driver is exercised on the four outcomes that matter: success, a permanent rejection (never
 * retried), a transient failure (retried), and a malformed provider response (treated as transient, because "we
 * do not know whether it went out" must not silently drop a cancelled-clinic message).
 */
final class DriverTest extends TestCase
{
    use NotificationFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->asTenant('a');
        Http::preventStrayRequests();
    }

    private function message(string $body = 'Serial A-012', string $recipient = '+8801712345678', ?string $template = null): OutboundMessage
    {
        return new OutboundMessage(
            channel: NotificationChannel::Sms,
            event: NotificationEvent::BookingConfirmed,
            recipient: $recipient,
            body: $body,
            locale: 'bn',
            providerTemplateId: $template,
            notificationId: 42,
        );
    }

    /** @param  array<string, mixed>  $options */
    private function sslDriver(array $options = []): SslWirelessSmsDriver
    {
        return new SslWirelessSmsDriver(
            new GatewayConfig(
                channel: NotificationChannel::Sms,
                provider: GatewayProvider::SslWireless,
                name: 'Primary',
                credentials: ['url' => 'https://smsplus.example.test/api/v3/send-sms', 'api_token' => 'secret-token', 'sid' => 'CLINICSID'],
                options: $options,
                senderId: 'CLINIC',
            ),
            new SegmentCounter,
        );
    }

    // ---- SSL Wireless ---------------------------------------------------------------------------------------

    public function test_ssl_wireless_success_returns_the_provider_reference(): void
    {
        Http::fake(['smsplus.example.test/*' => Http::response(['status' => 'SUCCESS', 'status_code' => 200, 'error_message' => '', 'smsinfo' => [['sms_status' => 'SUCCESS', 'reference_id' => 'ref-123']]])]);

        $result = $this->sslDriver()->send($this->message());

        $this->assertSame(NotificationLogStatus::Sent, $result->status);
        $this->assertSame('ref-123', $result->providerMessageId);
        $this->assertFalse($result->permanent);
    }

    /** Bangla must go out as UCS-2: an SMS sent as plain text arrives as question marks on a feature phone. */
    public function test_ssl_wireless_marks_a_bangla_body_as_unicode(): void
    {
        Http::fake(['smsplus.example.test/*' => Http::response(['status' => 'SUCCESS', 'smsinfo' => [['reference_id' => 'r1']]])]);

        $this->sslDriver()->send($this->message('আপনার সিরিয়াল A-012'));

        Http::assertSent(fn (Request $request) => ($request->data()['sms_type'] ?? null) === 'UNICODE');
    }

    public function test_ssl_wireless_leaves_a_latin_body_as_text(): void
    {
        Http::fake(['smsplus.example.test/*' => Http::response(['status' => 'SUCCESS', 'smsinfo' => [['reference_id' => 'r2']]])]);

        $this->sslDriver()->send($this->message('Serial A-012'));

        Http::assertSent(fn (Request $request) => ! array_key_exists('sms_type', $request->data()));
    }

    public function test_ssl_wireless_msisdn_is_sent_without_the_plus_and_the_log_request_masks_it(): void
    {
        Http::fake(['smsplus.example.test/*' => Http::response(['status' => 'SUCCESS', 'smsinfo' => [['reference_id' => 'r']]])]);

        $result = $this->sslDriver()->send($this->message(recipient: '01712345678'));

        Http::assertSent(fn (Request $request) => $request->data()['msisdn'] === '8801712345678');
        $this->assertSame('017*****678', $result->request['msisdn']);
        $this->assertArrayNotHasKey('api_token', $result->request, 'the logged request must never carry a credential');
        $this->assertArrayNotHasKey('sid', $result->request);
        $this->assertArrayNotHasKey('sms', $result->request);
    }

    public function test_ssl_wireless_permanent_rejection_is_never_retried(): void
    {
        Http::fake(['smsplus.example.test/*' => Http::response(['status' => 'FAILED', 'error_message' => 'INVALID_NUMBER'])]);

        $result = $this->sslDriver()->send($this->message());

        $this->assertSame(NotificationLogStatus::Rejected, $result->status);
        $this->assertTrue($result->permanent);
        $this->assertFalse($result->isRetryable());
        $this->assertSame('INVALID_NUMBER', $result->errorCode);
    }

    public function test_ssl_wireless_transient_failure_is_retryable(): void
    {
        Http::fake(['smsplus.example.test/*' => Http::response(['status' => 'FAILED', 'error_message' => 'SYSTEM_BUSY'])]);

        $result = $this->sslDriver()->send($this->message());

        $this->assertSame(NotificationLogStatus::Failed, $result->status);
        $this->assertTrue($result->isRetryable());
    }

    public function test_ssl_wireless_server_error_is_transient(): void
    {
        Http::fake(['smsplus.example.test/*' => Http::response('gateway down', 503)]);

        $result = $this->sslDriver()->send($this->message());

        $this->assertTrue($result->isRetryable());
        $this->assertSame('http_503', $result->errorCode);
    }

    /** A 200 whose body is not JSON: we do not know if it was sent, so we retry rather than drop it. */
    public function test_ssl_wireless_malformed_response_is_transient(): void
    {
        Http::fake(['smsplus.example.test/*' => Http::response('<html>maintenance</html>', 200)]);

        $result = $this->sslDriver()->send($this->message());

        $this->assertSame('malformed_response', $result->errorCode);
        $this->assertTrue($result->isRetryable());
    }

    public function test_ssl_wireless_connection_failure_is_transient(): void
    {
        Http::fake(['smsplus.example.test/*' => fn () => throw new ConnectionException('timed out')]);

        $result = $this->sslDriver()->send($this->message());

        $this->assertSame('connection', $result->errorCode);
        $this->assertTrue($result->isRetryable());
    }

    // ---- Generic HTTP ---------------------------------------------------------------------------------------

    /** @param  array<string, mixed>  $options */
    private function genericDriver(array $options = []): GenericHttpSmsDriver
    {
        return new GenericHttpSmsDriver(
            new GatewayConfig(
                channel: NotificationChannel::Sms,
                provider: GatewayProvider::BulkSmsBd,
                name: 'Aggregator',
                credentials: ['url' => 'https://sms.example.test/send', 'api_key' => 'k-123'],
                options: $options,
                senderId: 'CLINIC',
            ),
            new SegmentCounter,
        );
    }

    public function test_generic_driver_posts_the_configured_field_names(): void
    {
        Http::fake(['sms.example.test/*' => Http::response(['status' => 'success', 'message_id' => 'm-9'])]);

        $result = $this->genericDriver(['to_field' => 'msisdn', 'body_field' => 'text'])->send($this->message('Serial A-012'));

        Http::assertSent(fn (Request $request) => $request->data()['msisdn'] === '8801712345678' && $request->data()['text'] === 'Serial A-012' && $request->data()['api_key'] === 'k-123');
        $this->assertSame('m-9', $result->providerMessageId);
    }

    public function test_generic_driver_sets_the_unicode_flag_for_a_bangla_body(): void
    {
        Http::fake(['sms.example.test/*' => Http::response(['status' => 'success'])]);

        $this->genericDriver()->send($this->message('আপনার সিরিয়াল'));

        Http::assertSent(fn (Request $request) => ($request->data()['type'] ?? null) === 'unicode');
    }

    public function test_generic_driver_omits_the_unicode_flag_for_a_latin_body(): void
    {
        Http::fake(['sms.example.test/*' => Http::response(['status' => 'success'])]);

        $this->genericDriver()->send($this->message('Serial A-012'));

        Http::assertSent(fn (Request $request) => ! array_key_exists('type', $request->data()));
    }

    public function test_generic_driver_honours_the_tenants_unicode_opt_out(): void
    {
        Http::fake(['sms.example.test/*' => Http::response(['status' => 'success'])]);

        $this->genericDriver(['unicode' => false])->send($this->message('আপনার সিরিয়াল'));

        Http::assertSent(fn (Request $request) => ! array_key_exists('type', $request->data()));
    }

    public function test_generic_driver_classifies_a_configured_permanent_error(): void
    {
        Http::fake(['sms.example.test/*' => Http::response(['status' => 'failed', 'error_message' => 'INVALID_NUMBER'])]);

        $result = $this->genericDriver()->send($this->message());

        $this->assertTrue($result->permanent);
    }

    public function test_generic_driver_treats_an_unknown_error_as_transient(): void
    {
        Http::fake(['sms.example.test/*' => Http::response(['status' => 'failed', 'error_message' => 'QUEUE_FULL'])]);

        $this->assertTrue($this->genericDriver()->send($this->message())->isRetryable());
    }

    public function test_generic_driver_rejects_a_4xx_permanently(): void
    {
        Http::fake(['sms.example.test/*' => Http::response(['error' => 'bad request'], 400)]);

        $this->assertTrue($this->genericDriver()->send($this->message())->permanent);
    }

    public function test_generic_driver_retries_a_5xx(): void
    {
        Http::fake(['sms.example.test/*' => Http::response(['error' => 'oops'], 502)]);

        $this->assertTrue($this->genericDriver()->send($this->message())->isRetryable());
    }

    public function test_generic_driver_malformed_response_is_transient(): void
    {
        Http::fake(['sms.example.test/*' => Http::response('OK', 200)]);

        $this->assertSame('malformed_response', $this->genericDriver()->send($this->message())->errorCode);
    }

    // ---- WhatsApp Cloud -------------------------------------------------------------------------------------

    private function whatsappDriver(): WhatsAppCloudDriver
    {
        return new WhatsAppCloudDriver(new GatewayConfig(
            channel: NotificationChannel::Whatsapp,
            provider: GatewayProvider::WhatsappCloud,
            name: 'WA',
            credentials: ['access_token' => 'wa-token', 'phone_number_id' => '10000000001', 'base_url' => 'https://graph.example.test'],
        ));
    }

    public function test_whatsapp_sends_free_text_without_an_approved_template(): void
    {
        Http::fake(['graph.example.test/*' => Http::response(['messages' => [['id' => 'wamid.abc']]])]);

        $result = $this->whatsappDriver()->send($this->message('আপনার সিরিয়াল A-012'));

        $this->assertSame('wamid.abc', $result->providerMessageId);
        Http::assertSent(fn (Request $request) => $request->data()['type'] === 'text'
            && $request->data()['text']['body'] === 'আপনার সিরিয়াল A-012'
            && $request->hasHeader('Authorization', 'Bearer wa-token'));
    }

    public function test_whatsapp_uses_the_approved_template_with_the_recipients_language(): void
    {
        Http::fake(['graph.example.test/*' => Http::response(['messages' => [['id' => 'wamid.tpl']]])]);

        $this->whatsappDriver()->send(new OutboundMessage(
            channel: NotificationChannel::Whatsapp,
            event: NotificationEvent::BookingConfirmed,
            recipient: '+8801712345678',
            body: 'ignored when a template is used',
            locale: 'bn',
            payload: ['template_params' => ['A-012', 'ডা. রহমান']],
            providerTemplateId: 'booking_confirmed_v3',
        ));

        Http::assertSent(function (Request $request): bool {
            $data = $request->data();

            return $data['type'] === 'template'
                && $data['template']['name'] === 'booking_confirmed_v3'
                && $data['template']['language']['code'] === 'bn'
                && $data['template']['components'][0]['parameters'][1]['text'] === 'ডা. রহমান';
        });
    }

    public function test_whatsapp_undeliverable_number_is_permanent(): void
    {
        Http::fake(['graph.example.test/*' => Http::response(['error' => ['message' => 'not a WhatsApp user', 'code' => 131026]], 400)]);

        $result = $this->whatsappDriver()->send($this->message());

        $this->assertTrue($result->permanent);
        $this->assertSame('wa_131026', $result->errorCode);
    }

    public function test_whatsapp_rate_limit_is_transient(): void
    {
        Http::fake(['graph.example.test/*' => Http::response(['error' => ['message' => 'rate limited', 'code' => 131048]], 429)]);

        $this->assertTrue($this->whatsappDriver()->send($this->message())->isRetryable());
    }

    public function test_whatsapp_malformed_response_is_transient(): void
    {
        Http::fake(['graph.example.test/*' => Http::response('not json', 200)]);

        $this->assertSame('malformed_response', $this->whatsappDriver()->send($this->message())->errorCode);
    }

    public function test_whatsapp_without_credentials_is_rejected_rather_than_attempted(): void
    {
        $driver = new WhatsAppCloudDriver(new GatewayConfig(channel: NotificationChannel::Whatsapp, provider: GatewayProvider::WhatsappCloud, name: 'WA'));

        $this->assertSame('whatsapp_credentials_missing', $driver->send($this->message())->errorCode);
    }

    // ---- IVR ------------------------------------------------------------------------------------------------

    private function ivrDriver(): HttpIvrDriver
    {
        return new HttpIvrDriver(new GatewayConfig(
            channel: NotificationChannel::Ivr,
            provider: GatewayProvider::CustomHttp,
            name: 'IVR',
            credentials: ['url' => 'https://ivr.example.test/call', 'api_key' => 'ivr-key'],
            senderId: '09600000000',
        ));
    }

    public function test_ivr_starts_a_call_with_the_spoken_text(): void
    {
        Http::fake(['ivr.example.test/*' => Http::response(['call_id' => 'call-77', 'status' => 'queued'])]);

        $result = $this->ivrDriver()->send(new OutboundMessage(
            channel: NotificationChannel::Ivr,
            event: NotificationEvent::DoctorCancelled,
            recipient: '+8801712345678',
            body: 'আপনার সিরিয়াল বাতিল হয়েছে',
            locale: 'bn',
            payload: ['ivr_flow' => 'cancel_bn'],
        ));

        $this->assertSame('call-77', $result->providerMessageId);
        Http::assertSent(fn (Request $request) => $request->data()['to'] === '8801712345678'
            && $request->data()['flow_id'] === 'cancel_bn'
            && $request->data()['language'] === 'bn'
            && $request->hasHeader('Authorization', 'Bearer ivr-key'));
    }

    /** A patient name is attacker-controlled and ends up inside the vendor's SSML: it must arrive inert. */
    public function test_ivr_never_forwards_ssml_metacharacters(): void
    {
        Http::fake(['ivr.example.test/*' => Http::response(['call_id' => 'c1'])]);

        $this->ivrDriver()->send(new OutboundMessage(
            channel: NotificationChannel::Ivr,
            event: NotificationEvent::DoctorCancelled,
            recipient: '+8801712345678',
            body: 'Patient <break time="60s"/> & <prosody rate="x-slow">',
            locale: 'en',
        ));

        Http::assertSent(function (Request $request): bool {
            $text = $request->data()['text'];

            return ! str_contains($text, '<') && ! str_contains($text, '>') && ! str_contains($text, '&') && ! str_contains($text, '"');
        });
    }

    public function test_ivr_4xx_is_permanent(): void
    {
        Http::fake(['ivr.example.test/*' => Http::response(['error' => 'bad number'], 422)]);

        $this->assertTrue($this->ivrDriver()->send($this->message())->permanent);
    }

    public function test_ivr_5xx_is_transient(): void
    {
        Http::fake(['ivr.example.test/*' => Http::response('', 500)]);

        $this->assertTrue($this->ivrDriver()->send($this->message())->isRetryable());
    }

    public function test_ivr_log_request_does_not_leak_a_credential_embedded_in_the_url(): void
    {
        Http::fake(['ivr.example.test/*' => Http::response(['call_id' => 'c'])]);

        $driver = new HttpIvrDriver(new GatewayConfig(
            channel: NotificationChannel::Ivr,
            provider: GatewayProvider::CustomHttp,
            name: 'IVR',
            credentials: ['url' => 'https://ivr.example.test/call?key=super-secret'],
        ));

        $result = $driver->send($this->message());

        $this->assertSame('https://ivr.example.test/call', $result->request['url']);
        $this->assertStringNotContainsString('super-secret', json_encode($result->request) ?: '');
    }

    // ---- Web push -------------------------------------------------------------------------------------------

    private function pushDriver(): WebPushDriver
    {
        [$key, $point] = Ec::generate();
        $details = openssl_pkey_get_details($key);

        return new WebPushDriver(
            new VapidSigner(Ec::base64UrlEncode($point), Ec::base64UrlEncode(str_pad((string) $details['ec']['d'], 32, "\x00", STR_PAD_LEFT)), 'mailto:ops@example.test'),
            new WebPushEncryptor,
        );
    }

    private function subscription(): PushSubscription
    {
        [, $point] = Ec::generate();

        return PushSubscription::factory()->create([
            'subscriber_type' => Patient::class,
            'subscriber_id' => $this->patient()->id,
            'endpoint' => 'https://push.example.test/send/abc',
            'endpoint_hash' => PushSubscription::hash('https://push.example.test/send/abc'),
            'keys' => ['p256dh' => Ec::base64UrlEncode($point), 'auth' => Ec::base64UrlEncode(random_bytes(16))],
        ]);
    }

    public function test_web_push_sends_an_encrypted_body_with_the_vapid_header(): void
    {
        $this->subscription();
        Http::fake(['push.example.test/*' => Http::response('', 201)]);

        $result = $this->pushDriver()->send(new OutboundMessage(
            channel: NotificationChannel::Push,
            event: NotificationEvent::ThreeAhead,
            recipient: 'https://push.example.test/send/abc',
            body: 'আপনার আগে ৩ জন',
            subject: 'সেবা হাসপাতাল',
            locale: 'bn',
        ));

        $this->assertTrue($result->isSuccess());
        Http::assertSent(fn (Request $request) => $request->hasHeader('Content-Encoding', 'aes128gcm')
            && str_starts_with((string) $request->header('Authorization')[0], 'vapid t=')
            && $request->header('Urgency')[0] === 'high'
            && ! str_contains($request->body(), 'আপনার'));
    }

    public function test_web_push_prunes_a_subscription_the_push_service_reports_as_gone(): void
    {
        $subscription = $this->subscription();
        Http::fake(['push.example.test/*' => Http::response('', 410)]);

        $result = $this->pushDriver()->send(new OutboundMessage(
            channel: NotificationChannel::Push,
            event: NotificationEvent::ThreeAhead,
            recipient: $subscription->endpoint,
            body: 'x',
        ));

        $this->assertTrue($result->permanent);
        $this->assertSame('subscription_expired', $result->errorCode);
        $this->assertNull(PushSubscription::query()->find($subscription->id));
    }

    public function test_web_push_without_a_stored_subscription_is_rejected(): void
    {
        $result = $this->pushDriver()->send(new OutboundMessage(
            channel: NotificationChannel::Push,
            event: NotificationEvent::ThreeAhead,
            recipient: 'https://push.example.test/send/unknown',
            body: 'x',
        ));

        $this->assertSame('push_subscription_missing', $result->errorCode);
        $this->assertTrue($result->permanent);
    }

    // ---- resolution -----------------------------------------------------------------------------------------

    public function test_a_tenant_without_a_gateway_falls_back_to_the_log_driver_rather_than_failing(): void
    {
        $driver = app(DriverFactory::class)->for(NotificationChannel::Sms);

        $this->assertSame('log', $driver->provider());
        $this->assertTrue($driver->send($this->message())->isSuccess());
    }

    public function test_the_provider_of_a_gateway_row_selects_its_driver(): void
    {
        $this->smsGateway(['provider' => GatewayProvider::SslWireless]);
        $this->assertSame('ssl_wireless', app(DriverFactory::class)->for(NotificationChannel::Sms)->provider());

        $this->whatsappGateway();
        $this->assertSame('whatsapp_cloud', app(DriverFactory::class)->for(NotificationChannel::Whatsapp)->provider());

        $this->ivrGateway();
        $this->assertSame('custom_http', app(DriverFactory::class)->for(NotificationChannel::Ivr)->provider());
    }

    public function test_the_default_row_wins_over_a_higher_priority_non_default_row(): void
    {
        $this->smsGateway(['name' => 'Backup', 'provider' => GatewayProvider::BulkSmsBd, 'is_default' => false, 'priority' => 1]);
        $this->smsGateway(['name' => 'Primary', 'provider' => GatewayProvider::SslWireless, 'is_default' => true, 'priority' => 50]);

        $this->assertSame('Primary', app(DriverFactory::class)->configFor(NotificationChannel::Sms)?->name);
    }

    public function test_an_inactive_gateway_is_ignored(): void
    {
        $this->smsGateway(['is_active' => false]);

        $this->assertNull(app(DriverFactory::class)->configFor(NotificationChannel::Sms));
    }
}
