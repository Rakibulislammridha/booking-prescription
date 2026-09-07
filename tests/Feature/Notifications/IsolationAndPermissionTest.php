<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Domain\Clinic\Enums\Permission;
use App\Domain\Clinic\Enums\Role;
use App\Domain\Clinic\Support\RoleMatrix;
use App\Domain\Notifications\Contracts\DriverFactory;
use App\Domain\Notifications\Enums\GatewayProvider;
use App\Domain\Notifications\Enums\NotificationChannel;
use App\Models\Tenant\Notification;
use App\Models\Tenant\NotificationLog;
use App\Models\Tenant\NotificationTemplate;
use App\Models\Tenant\PushSubscription;
use App\Models\Tenant\SmsGatewaySetting;
use App\Tenancy\Exceptions\TenancyNotInitialized;
use App\Tenancy\Facades\Tenancy;
use Inertia\Testing\AssertableInertia;
use Tests\Feature\Notifications\Concerns\NotificationFixtures;
use Tests\TestCase;

/**
 * Tenant isolation and the permission matrix. The isolation claim that matters most here is about CREDENTIALS: a
 * gateway row is a clinic's paid SMS account, and tenant A's token must be structurally unable to send tenant B's
 * message.
 */
final class IsolationAndPermissionTest extends TestCase
{
    use NotificationFixtures;

    // ---- isolation ------------------------------------------------------------------------------------------

    public function test_every_table_this_module_writes_is_tenant_isolated(): void
    {
        $this->assertTenantIsolated('notifications', function (): void {
            Notification::factory()->create();
        });

        $this->assertTenantIsolated('notification_templates', function (): void {
            NotificationTemplate::factory()->create();
        });

        $this->assertTenantIsolated('notification_logs', function (): void {
            NotificationLog::factory()->create();
        });

        $this->assertTenantIsolated('sms_gateway_settings', function (): void {
            SmsGatewaySetting::factory()->create();
        });

        $this->assertTenantIsolated('push_subscriptions', function (): void {
            PushSubscription::factory()->create();
        });
    }

    public function test_the_models_refuse_to_query_without_tenancy(): void
    {
        Tenancy::check() && Tenancy::end();

        foreach ([Notification::class, NotificationTemplate::class, NotificationLog::class, SmsGatewaySetting::class, PushSubscription::class] as $model) {
            $this->assertThrows(fn () => $model::query()->count(), TenancyNotInitialized::class);
        }
    }

    /** The isolation claim with teeth: tenant B's send never reaches for tenant A's credentials. */
    public function test_a_gateway_configured_for_one_tenant_is_invisible_to_another(): void
    {
        $this->asTenant('a');
        SmsGatewaySetting::factory()->create([
            'name' => 'Tenant A gateway',
            'provider' => GatewayProvider::SslWireless,
            'credentials' => ['url' => 'https://a.example.test/send', 'api_token' => 'A-SECRET', 'sid' => 'A-SID'],
        ]);

        $this->assertSame('A-SECRET', app(DriverFactory::class)->configFor(NotificationChannel::Sms)->credential('api_token'));

        $this->asTenant('b');
        $this->assertNull(app(DriverFactory::class)->configFor(NotificationChannel::Sms), 'tenant B sees no gateway at all');
        $this->assertSame('log', app(DriverFactory::class)->for(NotificationChannel::Sms)->provider(), 'and falls back to the log driver rather than borrowing one');
    }

    public function test_each_tenant_resolves_its_own_credentials(): void
    {
        $this->asTenant('b');
        SmsGatewaySetting::factory()->create([
            'name' => 'Tenant B gateway',
            'provider' => GatewayProvider::SslWireless,
            'credentials' => ['url' => 'https://b.example.test/send', 'api_token' => 'B-SECRET', 'sid' => 'B-SID'],
        ]);

        $config = app(DriverFactory::class)->configFor(NotificationChannel::Sms);
        $this->assertSame('B-SECRET', $config->credential('api_token'));
        $this->assertSame('https://b.example.test/send', $config->credential('url'));
    }

    public function test_a_notification_written_for_one_tenant_is_not_visible_to_the_other(): void
    {
        $this->asTenant('a');
        Notification::factory()->count(2)->create();
        $this->assertSame(2, Notification::query()->count());

        $this->asTenant('b');
        $this->assertSame(0, Notification::query()->count());
    }

    // ---- permissions ----------------------------------------------------------------------------------------

    public function test_a_receptionist_cannot_reach_the_notification_screens(): void
    {
        $this->asTenant('a');
        $this->actingAsStaff(Role::Receptionist);

        $this->get('/panel/notifications')->assertForbidden();
        $this->get('/panel/notifications/templates')->assertForbidden();
        $this->get('/panel/notifications/gateways')->assertForbidden();
        $this->get('/panel/notifications/push')->assertForbidden();
    }

    public function test_a_doctor_cannot_manage_templates_or_gateways(): void
    {
        $this->asTenant('a');
        $this->actingAsDoctor();

        $this->get('/panel/notifications/templates')->assertForbidden();
        $this->get('/panel/notifications/gateways')->assertForbidden();
        $this->post('/panel/notifications/templates', ['event_key' => 'three_ahead', 'channel' => 'sms', 'locale' => 'bn', 'body' => 'x'])->assertForbidden();
    }

    /**
     * SMS credits are money, so the accountant reads the outbound log — and nothing else. Sending (retry, gateway
     * test), the credentials and the push devices stay with the hospital admin.
     */
    public function test_an_accountant_reads_the_outbound_log_and_nothing_else(): void
    {
        $this->asTenant('a');
        $this->actingAsStaff(Role::Accountant);
        $notification = Notification::factory()->create();

        $this->get('/panel/notifications')->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->component('Notifications/Index')->where('can.retry', false));
        $this->get('/panel/notifications/'.$notification->id)->assertOk();

        $this->post('/panel/notifications/'.$notification->id.'/retry')->assertForbidden();
        $this->get('/panel/notifications/templates')->assertForbidden();
        $this->get('/panel/notifications/gateways')->assertForbidden();
        $this->get('/panel/notifications/push')->assertForbidden();
    }

    public function test_the_hospital_admin_holds_every_notification_screen(): void
    {
        $this->asTenant('a');
        $this->actingAsStaff(Role::HospitalAdmin);

        $this->get('/panel/notifications')->assertOk();
        $this->get('/panel/notifications/templates')->assertOk();
        $this->get('/panel/notifications/gateways')->assertOk();
        $this->get('/panel/notifications/push')->assertOk();
    }

    public function test_a_guest_is_redirected_to_the_staff_login(): void
    {
        $this->asTenant('a');

        $this->get('/panel/notifications')->assertRedirect();
    }

    /** Each screen has its own permission: a template permission is not a key to the credentials or the log. */
    public function test_the_four_notification_permissions_are_independent(): void
    {
        $this->asTenant('a');
        $user = $this->actingAsStaff(Role::Receptionist);
        $user->givePermissionTo(Permission::NotificationsTemplatesManage->value);
        $user->forgetCachedPermissions();

        $this->get('/panel/notifications/templates')->assertOk();
        // credentials that spend the clinic's money are their own permission, not a side effect of templates
        $this->get('/panel/notifications/gateways')->assertForbidden();
        $this->get('/panel/notifications')->assertForbidden();
        $this->get('/panel/notifications/push')->assertForbidden();

        $user->givePermissionTo(Permission::NotificationsGatewaysManage->value);
        $user->forgetCachedPermissions();

        $this->get('/panel/notifications/gateways')->assertOk();
        $this->get('/panel/notifications/push')->assertOk();
        // …and still not the log, nor the ability to spend a credit
        $this->get('/panel/notifications')->assertForbidden();
        $this->post('/panel/notifications/'.Notification::factory()->create()->id.'/retry')->assertForbidden();
    }

    public function test_the_seeder_creates_the_notification_permissions_and_the_matrix_agrees(): void
    {
        $this->asTenant('a');

        foreach ([Permission::NotificationsLogsView, Permission::NotificationsGatewaysManage, Permission::NotificationsSendTest] as $permission) {
            $this->assertDatabaseHas('permissions', ['name' => $permission->value, 'guard_name' => 'web']);
        }

        $this->assertContains(Permission::NotificationsLogsView, RoleMatrix::permissionsFor(Role::Accountant));
        $this->assertNotContains(Permission::NotificationsGatewaysManage, RoleMatrix::permissionsFor(Role::Accountant));
        $this->assertNotContains(Permission::NotificationsSendTest, RoleMatrix::permissionsFor(Role::Accountant));

        foreach ([Role::Doctor, Role::Receptionist] as $role) {
            $this->assertNotContains(Permission::NotificationsLogsView, RoleMatrix::permissionsFor($role));
        }
    }
}
