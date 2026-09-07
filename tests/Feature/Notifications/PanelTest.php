<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Clinic\Enums\Role;
use App\Domain\Notifications\Enums\GatewayProvider;
use App\Domain\Notifications\Enums\NotificationChannel;
use App\Domain\Notifications\Enums\NotificationEvent;
use App\Domain\Notifications\Enums\NotificationStatus;
use App\Models\Tenant\AuditLog;
use App\Models\Tenant\Notification;
use App\Models\Tenant\NotificationLog;
use App\Models\Tenant\NotificationTemplate;
use App\Models\Tenant\PushSubscription;
use App\Models\Tenant\SmsGatewaySetting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;
use Tests\Feature\Notifications\Concerns\NotificationFixtures;
use Tests\TestCase;

/** The panel surface: the outbound log, template management with preview, gateways and the test-send action. */
final class PanelTest extends TestCase
{
    use NotificationFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->asTenant('a');
    }

    // ---- outbound log ---------------------------------------------------------------------------------------

    public function test_the_outbound_log_lists_and_filters(): void
    {
        $this->actingAsStaff(Role::HospitalAdmin);
        Notification::factory()->count(3)->create(['channel' => NotificationChannel::Sms, 'status' => NotificationStatus::Sent]);
        Notification::factory()->create(['channel' => NotificationChannel::Email, 'status' => NotificationStatus::Failed, 'event_key' => NotificationEvent::PrescriptionReady, 'recipient' => 'a@example.test']);

        $this->get('/panel/notifications')->assertInertia(fn (AssertableInertia $p) => $p
            ->component('Notifications/Index')
            ->has('notifications.data', 4)
            ->where('stats.failed', 1)
            ->has('options.channels', 5)
            ->has('options.events', 12),
        );

        $this->get('/panel/notifications?channel=email')
            ->assertInertia(fn (AssertableInertia $p) => $p->has('notifications.data', 1));

        $this->get('/panel/notifications?status=failed&event_key=prescription_ready')
            ->assertInertia(fn (AssertableInertia $p) => $p->has('notifications.data', 1));

        $this->get('/panel/notifications?status=delivered')
            ->assertInertia(fn (AssertableInertia $p) => $p->has('notifications.data', 0));
    }

    public function test_an_invalid_filter_value_is_ignored_rather_than_erroring(): void
    {
        $this->actingAsStaff(Role::HospitalAdmin);
        Notification::factory()->create();

        $this->get('/panel/notifications?channel=carrier-pigeon&from=yesterday')
            ->assertInertia(fn (AssertableInertia $p) => $p->where('filters.channel', null)->where('filters.from', null)->has('notifications.data', 1));
    }

    public function test_the_detail_endpoint_returns_every_attempt_and_writes_an_audit_view(): void
    {
        $this->actingAsStaff(Role::HospitalAdmin);
        $notification = Notification::factory()->create();
        NotificationLog::factory()->count(2)->sequence(['attempt_no' => 1], ['attempt_no' => 2])->create(['notification_id' => $notification->id]);

        $response = $this->getJson("/panel/notifications/{$notification->id}");

        $response->assertOk()->assertJsonCount(2, 'attempts')->assertJsonPath('data.id', $notification->id);
        $this->assertAudited(AuditAction::View, $notification, ['screen' => 'panel.notifications.show']);
    }

    public function test_a_dead_lettered_message_can_be_queued_again(): void
    {
        $this->actingAsStaff(Role::HospitalAdmin);
        $this->recordingDriver();
        $notification = Notification::factory()->failed()->create();

        $this->post("/panel/notifications/{$notification->id}/retry")
            ->assertRedirect('/panel/notifications');

        $notification->refresh();
        $this->assertSame(NotificationStatus::Sent, $notification->status);
        $this->assertAudited(AuditAction::Update, $notification);
    }

    // ---- templates ------------------------------------------------------------------------------------------

    public function test_the_template_screen_ships_the_defaults_and_the_variable_catalogue(): void
    {
        $this->actingAsStaff(Role::HospitalAdmin);

        $this->get('/panel/notifications/templates')->assertInertia(fn (AssertableInertia $p) => $p
            ->component('Notifications/Templates')
            ->has('defaults', 12 * 5 * 2)
            ->has('catalogue.three_ahead')
            ->has('templates', 0),
        );
    }

    public function test_saving_a_template_overrides_the_default_for_that_event_channel_and_locale(): void
    {
        $this->actingAsStaff(Role::HospitalAdmin);

        $this->post('/panel/notifications/templates', [
            'event_key' => 'three_ahead',
            'channel' => 'sms',
            'locale' => 'bn',
            'body' => '{{clinic}}: আপনার আগে {{ahead}} জন। সিরিয়াল {{serial}}।',
            'is_active' => true,
        ])->assertRedirect('/panel/notifications/templates');

        $template = NotificationTemplate::query()->firstOrFail();
        $this->assertSame(NotificationEvent::ThreeAhead, $template->event_key);
        $this->assertAudited(AuditAction::Create, $template);
    }

    public function test_saving_the_same_key_twice_updates_rather_than_duplicating(): void
    {
        $this->actingAsStaff(Role::HospitalAdmin);
        $payload = ['event_key' => 'three_ahead', 'channel' => 'sms', 'locale' => 'bn', 'body' => 'one {{serial}}'];

        $this->post('/panel/notifications/templates', $payload);
        $this->post('/panel/notifications/templates', [...$payload, 'body' => 'two {{serial}}']);

        $this->assertSame(1, NotificationTemplate::query()->count());
        $this->assertSame('two {{serial}}', NotificationTemplate::query()->value('body'));
    }

    /** An Inertia form post gets the domain error back as a validation error; an XHR client gets the dotted code. */
    public function test_a_placeholder_outside_the_events_catalogue_is_rejected(): void
    {
        $this->actingAsStaff(Role::HospitalAdmin);
        $payload = ['event_key' => 'three_ahead', 'channel' => 'sms', 'locale' => 'bn', 'body' => 'Invoice {{invoice_no}}'];

        $this->post('/panel/notifications/templates', $payload)->assertSessionHasErrors('domain');
        $this->postJson('/panel/notifications/templates', $payload)
            ->assertStatus(422)
            ->assertJsonPath('code', 'notifications.unknown_placeholder');

        $this->assertSame(0, NotificationTemplate::query()->count());
    }

    public function test_the_preview_renders_sample_values_and_returns_the_billed_segment_count(): void
    {
        $this->actingAsStaff(Role::HospitalAdmin);

        $response = $this->postJson('/panel/notifications/templates/preview', [
            'event_key' => 'three_ahead',
            'channel' => 'sms',
            'locale' => 'bn',
            'body' => '{{clinic}}: আপনার আগে আর {{ahead}} জন রোগী আছেন। সিরিয়াল {{serial}}।',
        ]);

        $response->assertOk()
            ->assertJsonPath('segments.encoding', 'UCS-2')
            ->assertJsonPath('segments.per_segment', 70)
            ->assertJsonPath('unknown_placeholders', []);

        $this->assertStringContainsString('সেবা হাসপাতাল', $response->json('body'));
        $this->assertStringContainsString('A-012', $response->json('body'));
    }

    public function test_the_preview_names_an_unknown_placeholder_instead_of_failing(): void
    {
        $this->actingAsStaff(Role::HospitalAdmin);

        $this->postJson('/panel/notifications/templates/preview', [
            'event_key' => 'three_ahead', 'channel' => 'sms', 'locale' => 'en', 'body' => 'Hi {{invoice_no}}',
        ])->assertOk()->assertJsonPath('unknown_placeholders', ['invoice_no']);
    }

    public function test_a_template_can_be_reset_to_the_built_in_default(): void
    {
        $this->actingAsStaff(Role::HospitalAdmin);
        $template = NotificationTemplate::factory()->create();

        $this->delete("/panel/notifications/templates/{$template->id}")->assertRedirect();

        $this->assertSame(0, NotificationTemplate::query()->count());
    }

    // ---- gateways -------------------------------------------------------------------------------------------

    public function test_gateway_credentials_are_never_returned_to_the_browser(): void
    {
        $this->actingAsStaff(Role::HospitalAdmin);
        $this->smsGateway();

        $this->get('/panel/notifications/gateways')->assertInertia(fn (AssertableInertia $p) => $p
            ->component('Notifications/Gateways')
            ->has('gateways', 1)
            ->where('gateways.0.credential_keys', ['api_token', 'sid', 'url'])
            ->missing('gateways.0.credentials'),
        );

        $this->get('/panel/notifications/gateways')->assertDontSee('test-token');
    }

    public function test_creating_a_gateway_stores_encrypted_credentials(): void
    {
        $this->actingAsStaff(Role::HospitalAdmin);

        $this->post('/panel/notifications/gateways', [
            'channel' => 'sms',
            'provider' => 'ssl_wireless',
            'name' => 'Primary',
            'sender_id' => 'CLINIC',
            'is_default' => true,
            'is_active' => true,
            'credentials' => ['api_token' => 'live-token', 'sid' => 'LIVESID'],
        ])->assertRedirect('/panel/notifications/gateways');

        $gateway = SmsGatewaySetting::query()->firstOrFail();
        $this->assertSame('live-token', $gateway->credentials['api_token']);
        $this->assertStringNotContainsString('live-token', (string) DB::table('sms_gateway_settings')->value('credentials'), 'the column is encrypted at rest');
        $this->assertAudited(AuditAction::Create, $gateway);
    }

    public function test_the_audit_trail_never_records_a_credential(): void
    {
        $this->actingAsStaff(Role::HospitalAdmin);
        $gateway = $this->smsGateway();

        $log = AuditLog::query()->where('auditable_type', SmsGatewaySetting::class)->where('auditable_id', $gateway->id)->firstOrFail();

        $this->assertSame('[encrypted]', $log->after['credentials'] ?? null);
    }

    public function test_updating_a_gateway_without_re_entering_the_token_keeps_it(): void
    {
        $this->actingAsStaff(Role::HospitalAdmin);
        $gateway = $this->smsGateway();

        $this->patch("/panel/notifications/gateways/{$gateway->id}", [
            'channel' => 'sms', 'provider' => 'ssl_wireless', 'name' => 'Renamed', 'sender_id' => 'NEWID', 'credentials' => ['api_token' => ''],
        ])->assertRedirect();

        $gateway->refresh();
        $this->assertSame('Renamed', $gateway->name);
        $this->assertSame('test-token', $gateway->credentials['api_token']);
    }

    public function test_promoting_a_gateway_to_default_demotes_the_previous_one(): void
    {
        $this->actingAsStaff(Role::HospitalAdmin);
        $first = $this->smsGateway(['name' => 'First', 'is_default' => true]);

        $this->post('/panel/notifications/gateways', [
            'channel' => 'sms', 'provider' => 'bulksmsbd', 'name' => 'Second', 'is_default' => true,
            'credentials' => ['url' => 'https://sms.example.test/send', 'api_key' => 'k'],
        ])->assertRedirect();

        $this->assertFalse($first->refresh()->is_default);
        $this->assertSame(1, SmsGatewaySetting::query()->where('is_default', true)->count());
    }

    public function test_the_test_send_goes_through_the_gateway_without_touching_the_ledger(): void
    {
        $this->actingAsStaff(Role::HospitalAdmin);
        Http::fake(['smsplus.example.test/*' => Http::response(['status' => 'SUCCESS', 'smsinfo' => [['reference_id' => 'r']]])]);
        $gateway = $this->smsGateway(['credentials' => ['url' => 'https://smsplus.example.test/api/v3/send-sms', 'api_token' => 't', 'sid' => 's']]);

        $this->post("/panel/notifications/gateways/{$gateway->id}/test", [
            'recipient' => '01712345678',
            'body' => 'পরীক্ষামূলক বার্তা',
        ])->assertRedirect()->assertSessionHas('flash.success');

        $this->assertSame(0, Notification::query()->count(), 'an operator test is not a patient message');
        $this->assertAudited(AuditAction::Share, $gateway, ['test_message' => true, 'encoding' => 'UCS-2']);
    }

    public function test_a_failing_test_send_reports_the_provider_error(): void
    {
        $this->actingAsStaff(Role::HospitalAdmin);
        Http::fake(['smsplus.example.test/*' => Http::response(['status' => 'FAILED', 'error_message' => 'INVALID_API_TOKEN'])]);
        $gateway = $this->smsGateway(['credentials' => ['url' => 'https://smsplus.example.test/api/v3/send-sms', 'api_token' => 'wrong', 'sid' => 's']]);

        $this->post("/panel/notifications/gateways/{$gateway->id}/test", ['recipient' => '01712345678', 'body' => 'test'])
            ->assertRedirect()->assertSessionHas('flash.error');
    }

    public function test_a_test_send_validates_the_mobile_number(): void
    {
        $this->actingAsStaff(Role::HospitalAdmin);
        $gateway = $this->smsGateway();

        $this->post("/panel/notifications/gateways/{$gateway->id}/test", ['recipient' => '12345', 'body' => 'test'])
            ->assertSessionHasErrors('recipient');
    }

    public function test_only_the_documented_providers_are_offered_per_channel(): void
    {
        $this->actingAsStaff(Role::HospitalAdmin);

        $this->get('/panel/notifications/gateways')->assertInertia(fn (AssertableInertia $p) => $p
            ->where('options.providers.whatsapp', ['whatsapp_cloud', 'infobip', 'twilio', 'custom_http'])
            ->where('options.channels', ['sms', 'whatsapp', 'ivr']),
        );

        $this->assertSame(9, count(GatewayProvider::values()));
    }

    // ---- push -----------------------------------------------------------------------------------------------

    public function test_the_push_screen_lists_subscriptions_and_reports_whether_vapid_is_configured(): void
    {
        $this->actingAsStaff(Role::HospitalAdmin);
        PushSubscription::factory()->create();

        $this->get('/panel/notifications/push')->assertInertia(fn (AssertableInertia $p) => $p
            ->component('Notifications/PushSubscriptions')
            ->has('subscriptions', 1)
            ->where('vapid_configured', false)
            ->missing('subscriptions.0.keys')
            ->missing('subscriptions.0.endpoint'),
        );
    }

    public function test_a_push_subscription_can_be_revoked(): void
    {
        $this->actingAsStaff(Role::HospitalAdmin);
        $subscription = PushSubscription::factory()->create();

        $this->delete("/panel/notifications/push/{$subscription->id}")->assertRedirect();

        $this->assertSame(0, PushSubscription::query()->count());
    }
}
