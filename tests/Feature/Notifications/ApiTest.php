<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Domain\Clinic\Enums\Role;
use App\Domain\Notifications\Enums\NotificationLogStatus;
use App\Domain\Notifications\Enums\NotificationStatus;
use App\Domain\Notifications\Support\Ec;
use App\Models\Tenant\Notification;
use App\Models\Tenant\NotificationLog;
use App\Models\Tenant\Patient;
use App\Models\Tenant\PushSubscription;
use App\Models\Tenant\SmsGatewaySetting;
use App\Models\Tenant\User;
use Tests\Feature\Notifications\Concerns\NotificationFixtures;
use Tests\TestCase;

/** The api surface: the service-worker push handshake and the gateway delivery-receipt webhook. */
final class ApiTest extends TestCase
{
    use NotificationFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->asTenant('a');
    }

    /** @return array<string, mixed> */
    private function subscriptionPayload(string $endpoint = 'https://push.example.test/send/abc'): array
    {
        [, $point] = Ec::generate();

        return [
            'endpoint' => $endpoint,
            'keys' => ['p256dh' => Ec::base64UrlEncode($point), 'auth' => Ec::base64UrlEncode(random_bytes(16))],
        ];
    }

    // ---- push -----------------------------------------------------------------------------------------------

    public function test_the_vapid_public_key_endpoint_reports_null_when_the_server_has_no_key_pair(): void
    {
        $this->actingAsStaff(Role::HospitalAdmin);

        $this->getJson('/api/notifications/push/key')->assertOk()->assertJsonPath('public_key', null);
    }

    public function test_the_vapid_public_key_is_served_when_configured(): void
    {
        [, $point] = Ec::generate();
        config(['notifications.push.vapid.public_key' => Ec::base64UrlEncode($point), 'notifications.push.vapid.private_key' => 'x', 'notifications.push.vapid.subject' => 'mailto:a@b.test']);
        $this->actingAsStaff(Role::HospitalAdmin);

        $this->getJson('/api/notifications/push/key')->assertOk()->assertJsonPath('public_key', Ec::base64UrlEncode($point));
    }

    public function test_a_staff_user_subscribes_this_browser_to_push(): void
    {
        $user = $this->actingAsStaff(Role::HospitalAdmin);

        $this->postJson('/api/notifications/push/subscribe', $this->subscriptionPayload())->assertCreated();

        $subscription = PushSubscription::query()->firstOrFail();
        $this->assertSame(User::class, $subscription->subscriber_type);
        $this->assertSame($user->id, $subscription->subscriber_id);
        $this->assertSame(PushSubscription::hash('https://push.example.test/send/abc'), $subscription->endpoint_hash);
    }

    public function test_a_patient_subscribes_from_the_portal(): void
    {
        $patient = $this->actingAsPatient($this->patient());

        $this->postJson('/api/notifications/push/subscribe', $this->subscriptionPayload())->assertCreated();

        $this->assertSame(Patient::class, PushSubscription::query()->value('subscriber_type'));
        $this->assertSame($patient->id, PushSubscription::query()->value('subscriber_id'));
    }

    /** A browser re-subscribes on every service-worker update: the row is refreshed, never duplicated. */
    public function test_re_subscribing_the_same_endpoint_updates_the_existing_row(): void
    {
        $this->actingAsStaff(Role::HospitalAdmin);
        $payload = $this->subscriptionPayload();

        $this->postJson('/api/notifications/push/subscribe', $payload)->assertCreated();
        $this->postJson('/api/notifications/push/subscribe', $payload)->assertCreated();

        $this->assertSame(1, PushSubscription::query()->count());
    }

    public function test_a_guest_cannot_subscribe(): void
    {
        $this->postJson('/api/notifications/push/subscribe', $this->subscriptionPayload())->assertUnauthorized();
    }

    public function test_a_malformed_subscription_is_rejected(): void
    {
        $this->actingAsStaff(Role::HospitalAdmin);

        $this->postJson('/api/notifications/push/subscribe', ['endpoint' => 'not-a-url', 'keys' => []])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['endpoint', 'keys.p256dh', 'keys.auth']);
    }

    public function test_a_subscriber_can_unsubscribe_their_own_endpoint(): void
    {
        $this->actingAsStaff(Role::HospitalAdmin);
        $payload = $this->subscriptionPayload();
        $this->postJson('/api/notifications/push/subscribe', $payload);

        $this->deleteJson('/api/notifications/push/subscribe', ['endpoint' => $payload['endpoint']])->assertOk();

        $this->assertSame(0, PushSubscription::query()->count());
    }

    public function test_one_user_cannot_unsubscribe_another_users_endpoint(): void
    {
        $this->actingAsStaff(Role::HospitalAdmin);
        $payload = $this->subscriptionPayload();
        $this->postJson('/api/notifications/push/subscribe', $payload);

        $this->actingAsStaff(Role::Receptionist);
        $this->deleteJson('/api/notifications/push/subscribe', ['endpoint' => $payload['endpoint']])->assertOk();

        $this->assertSame(1, PushSubscription::query()->count(), 'the endpoint belongs to somebody else');
    }

    // ---- delivery receipts ----------------------------------------------------------------------------------

    /** @return array{0: SmsGatewaySetting, 1: Notification} */
    private function sentNotification(string $providerMessageId = 'ref-1'): array
    {
        $gateway = $this->smsGateway(['credentials' => ['url' => 'https://x.test', 'api_token' => 't', 'sid' => 's', 'dlr_secret' => 'shhh']]);
        $notification = Notification::factory()->sent()->create();
        NotificationLog::factory()->create([
            'notification_id' => $notification->id,
            'provider' => $gateway->provider->value,
            'provider_message_id' => $providerMessageId,
            'status' => NotificationLogStatus::Sent,
        ]);

        return [$gateway, $notification];
    }

    public function test_a_delivery_receipt_marks_the_notification_delivered(): void
    {
        [$gateway, $notification] = $this->sentNotification();

        $this->postJson("/api/notifications/dlr/{$gateway->id}", ['message_id' => 'ref-1', 'status' => 'DELIVRD'], ['X-Dlr-Secret' => 'shhh'])
            ->assertOk()
            ->assertJsonPath('delivered', true);

        $notification->refresh();
        $this->assertSame(NotificationStatus::Delivered, $notification->status);
        $this->assertNotNull($notification->delivered_at);
    }

    public function test_a_failure_receipt_marks_the_notification_failed(): void
    {
        [$gateway, $notification] = $this->sentNotification();

        $this->postJson("/api/notifications/dlr/{$gateway->id}", ['message_id' => 'ref-1', 'status' => 'UNDELIV'], ['X-Dlr-Secret' => 'shhh'])
            ->assertOk()
            ->assertJsonPath('delivered', false);

        $this->assertSame(NotificationStatus::Failed, $notification->refresh()->status);
        $this->assertSame('dlr_undeliv', $notification->last_error);
    }

    public function test_a_receipt_without_the_gateway_secret_is_a_404(): void
    {
        [$gateway, $notification] = $this->sentNotification();

        $this->postJson("/api/notifications/dlr/{$gateway->id}", ['message_id' => 'ref-1', 'status' => 'DELIVRD'])->assertNotFound();
        $this->postJson("/api/notifications/dlr/{$gateway->id}", ['message_id' => 'ref-1', 'status' => 'DELIVRD'], ['X-Dlr-Secret' => 'wrong'])->assertNotFound();

        $this->assertSame(NotificationStatus::Sent, $notification->refresh()->status);
    }

    public function test_an_unmatched_receipt_is_accepted_but_changes_nothing(): void
    {
        [$gateway] = $this->sentNotification();

        $this->postJson("/api/notifications/dlr/{$gateway->id}", ['message_id' => 'unknown', 'status' => 'DELIVRD'], ['X-Dlr-Secret' => 'shhh'])
            ->assertOk()
            ->assertJsonPath('matched', false);
    }

    public function test_a_receipt_without_a_message_id_is_a_422(): void
    {
        [$gateway] = $this->sentNotification();

        $this->postJson("/api/notifications/dlr/{$gateway->id}", ['status' => 'DELIVRD'], ['X-Dlr-Secret' => 'shhh'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'notifications.dlr_missing_id');
    }
}
