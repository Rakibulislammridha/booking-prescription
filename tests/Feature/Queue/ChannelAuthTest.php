<?php

declare(strict_types=1);

namespace Tests\Feature\Queue;

use App\Domain\Clinic\Enums\Role;
use App\Domain\Queue\QueueServiceProvider;
use App\Domain\Queue\TenantChannel;
use App\Models\Tenant\Branch;
use App\Models\Tenant\Doctor;
use App\Models\Tenant\ReceptionDevice;
use App\Models\Tenant\User;
use App\Tenancy\Facades\Tenancy;
use Illuminate\Http\Response;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Group;
use Tests\Feature\Queue\Concerns\QueueFixtures;
use Tests\TestCase;

/**
 * REALTIME.md §2 / §13.1 — POST /broadcasting/auth (staff session) and POST /api/device/broadcasting/auth
 * (device bearer token). The `log` broadcaster authorises everything, so these run on the real reverb broadcaster.
 */
#[Group('realtime')]
final class ChannelAuthTest extends TestCase
{
    use QueueFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => 'test-key',
            'broadcasting.connections.reverb.secret' => 'test-secret',
            'broadcasting.connections.reverb.app_id' => 'test-app',
            'broadcasting.connections.reverb.options' => ['host' => '127.0.0.1', 'port' => 8080, 'scheme' => 'http', 'useTLS' => false],
        ]);
        QueueServiceProvider::registerChannels();   // Broadcast::channel() registers on the CURRENT default driver
        $this->asTenant('a');
    }

    private function tenantId(): string
    {
        return (string) Tenancy::current()?->public_id;
    }

    /** @return TestResponse<Response> */
    private function authAs(string $channel): TestResponse
    {
        return $this->post('/broadcasting/auth', ['channel_name' => $channel, 'socket_id' => '1234.5678']);
    }

    /** @return TestResponse<Response> */
    private function authAsDevice(string $token, string $channel): TestResponse
    {
        // RequestGuard caches its user for the life of the container; a second request in the same test would
        // otherwise be authorised as the previous device.
        $this->app['auth']->forgetGuards();

        return $this->post('/api/device/broadcasting/auth', ['channel_name' => $channel, 'socket_id' => '1234.5678'], [
            'Authorization' => 'Bearer '.$token,
            'Accept' => 'application/json',
        ]);
    }

    public function test_reception_channel_allows_branch_staff_and_branch_devices_and_denies_other_branches(): void
    {
        $branch = $this->mainBranch();
        $other = Branch::factory()->create();

        $this->actingAsStaff(Role::Receptionist, $branch);
        $this->authAs('private-'.TenantChannel::receptionName($this->tenantId(), $branch->public_id))->assertOk();
        $this->authAs('private-'.TenantChannel::receptionName($this->tenantId(), $other->public_id))->assertForbidden();

        $device = ReceptionDevice::factory()->create(['branch_id' => $branch->id]);
        $token = $device->createToken('device', ReceptionDevice::ABILITIES)->plainTextToken;
        $this->authAsDevice($token, 'private-'.TenantChannel::receptionName($this->tenantId(), $branch->public_id))->assertOk();
        $this->authAsDevice($token, 'private-'.TenantChannel::receptionName($this->tenantId(), $other->public_id))->assertForbidden();
    }

    public function test_a_channel_naming_another_tenant_is_denied_even_for_a_valid_staff_user(): void
    {
        $branch = $this->mainBranch();
        $this->actingAsStaff(Role::Receptionist, $branch);

        $foreignTenant = $this->tenant('b')->public_id;
        $this->authAs('private-'.TenantChannel::receptionName($foreignTenant, $branch->public_id))->assertForbidden();
    }

    public function test_doctor_channel_allows_the_doctor_and_operators_and_denies_other_doctors(): void
    {
        $branch = $this->mainBranch();
        $user = $this->actingAsDoctor();
        $own = Doctor::query()->where('user_id', $user->id)->firstOrFail();
        $foreign = $this->queueDoctor('dr-other');

        $this->authAs('private-'.TenantChannel::doctorName($this->tenantId(), $own->public_id))->assertOk();
        $this->authAs('private-'.TenantChannel::doctorName($this->tenantId(), $foreign->public_id))->assertForbidden();

        // a receptionist with queue.call-next may drive any doctor's screen
        $this->actingAsStaff(Role::Receptionist, $branch);
        $this->authAs('private-'.TenantChannel::doctorName($this->tenantId(), $foreign->public_id))->assertOk();

        // an accountant has no queue permission at all
        $this->actingAsStaff(Role::Accountant, $branch);
        $this->authAs('private-'.TenantChannel::doctorName($this->tenantId(), $foreign->public_id))->assertForbidden();
    }

    public function test_display_channel_allows_display_devices_and_denies_reception_devices(): void
    {
        $branch = $this->mainBranch();
        $channel = 'private-'.TenantChannel::displayName($this->tenantId(), $branch->public_id);

        $display = ReceptionDevice::factory()->display()->create(['branch_id' => $branch->id]);
        $displayToken = $display->createToken('display', ReceptionDevice::ABILITIES)->plainTextToken;
        $this->authAsDevice($displayToken, $channel)->assertOk();

        $desk = ReceptionDevice::factory()->create(['branch_id' => $branch->id]);
        $deskToken = $desk->createToken('desk', ReceptionDevice::ABILITIES)->plainTextToken;
        $this->authAsDevice($deskToken, $channel)->assertForbidden();

        $revoked = ReceptionDevice::factory()->display()->revoked()->create(['branch_id' => $branch->id]);
        $revokedToken = $revoked->createToken('old', ReceptionDevice::ABILITIES)->plainTextToken;
        $this->authAsDevice($revokedToken, $channel)->assertForbidden();

        // a display device of another branch is denied
        $elsewhere = ReceptionDevice::factory()->display()->create(['branch_id' => Branch::factory()->create()->id]);
        $this->authAsDevice($elsewhere->createToken('tv', ReceptionDevice::ABILITIES)->plainTextToken, $channel)->assertForbidden();
    }

    public function test_a_deactivated_staff_user_loses_every_private_channel(): void
    {
        $branch = $this->mainBranch();
        $user = $this->actingAsStaff(Role::Receptionist, $branch);
        $user->forceFill(['is_active' => false])->save();

        $this->authAs('private-'.TenantChannel::receptionName($this->tenantId(), $branch->public_id))->assertForbidden();
        $this->authAs('private-'.TenantChannel::displayName($this->tenantId(), $branch->public_id))->assertForbidden();
    }

    public function test_the_public_queue_channel_needs_no_auth_and_is_not_registered_as_private(): void
    {
        $session = $this->queueSession();
        $this->authAs(TenantChannel::queueName($this->tenantId(), $session->public_id))->assertForbidden();

        // …because a public channel never reaches /broadcasting/auth: the page just subscribes and reads it
        $this->get('/queue/'.$session->doctor->slug.'/state')->assertOk();
    }

    public function test_an_unauthenticated_request_cannot_open_a_private_channel(): void
    {
        $branch = $this->mainBranch();
        $this->post('/broadcasting/auth', ['channel_name' => 'private-'.TenantChannel::receptionName($this->tenantId(), $branch->public_id), 'socket_id' => '1234.5678'], ['Accept' => 'application/json'])
            ->assertStatus(403);
    }
}
