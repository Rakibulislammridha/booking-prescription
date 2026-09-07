<?php

declare(strict_types=1);

namespace Tests\Feature\Reception;

use App\Domain\Clinic\Enums\Role;
use App\Domain\Reception\Enums\DeviceStatus;
use App\Models\Tenant\Branch;
use App\Models\Tenant\ReceptionDevice;
use App\Models\Tenant\SerialBlock;
use Tests\Feature\Reception\Concerns\ReceptionFixtures;
use Tests\TestCase;

/** OFFLINE §2 / §12.2: registration issues a token with abilities + expiry; re-register rotates; revoked → 401; wrong branch actor → 403. */
final class DeviceRegistrationTest extends TestCase
{
    use ReceptionFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->asTenant('a');
    }

    public function test_register_issues_a_device_token_and_re_registration_rotates_it(): void
    {
        $this->actingAsStaff(Role::Receptionist);
        $branch = $this->mainBranch();
        $body = ['name' => 'Front desk tablet 2', 'branch' => $branch->public_id, 'kind' => 'reception', 'device_fingerprint' => hash('sha256', 'ua+screen+platform'), 'app_version' => '1.4.2'];

        $first = $this->postJson(route('api.reception.devices.register', [], false), $body)->assertCreated()
            ->assertJsonPath('device.name', 'Front desk tablet 2')->assertJsonPath('device.number', 1)->assertJsonPath('device.branch_id', $branch->public_id)
            ->assertJsonPath('abilities', ReceptionDevice::ABILITIES)->assertJsonPath('rotated', false);
        $token = (string) $first->json('token');
        $this->assertStringContainsString('|', $token);
        $this->assertNotNull($first->json('expires_at'));

        $device = ReceptionDevice::query()->where('public_id', (string) $first->json('device.public_id'))->firstOrFail();
        $this->assertSame(1, $device->tokens()->count());
        $this->assertTrue($device->tokens()->first()?->expires_at?->between(now()->addDays(89), now()->addDays(91)) ?? false);

        $second = $this->postJson(route('api.reception.devices.register', [], false), array_merge($body, ['name' => 'Renamed']))->assertCreated()->assertJsonPath('rotated', true)->assertJsonPath('device.public_id', $device->public_id);
        $this->assertSame(1, $device->tokens()->count(), 'the old token was revoked');
        $this->assertNotSame($token, $second->json('token'));

        // the old token no longer authenticates
        $actor = $this->receptionist();
        $this->withToken($token)->withHeader('X-Actor-User', $actor->public_id)->getJson(route('api.reception.bootstrap', [], false))->assertStatus(401)->assertJsonPath('code', 'reception.device_not_active');
        $this->withToken((string) $second->json('token'))->withHeader('X-Actor-User', $actor->public_id)->getJson(route('api.reception.bootstrap', [], false))->assertOk()->assertJsonPath('device.public_id', $device->public_id);

        // a second device at the branch gets number 2
        $this->postJson(route('api.reception.devices.register', [], false), array_merge($body, ['device_fingerprint' => hash('sha256', 'other')]))->assertCreated()->assertJsonPath('device.number', 2);
    }

    public function test_registration_requires_the_permission(): void
    {
        $this->actingAsStaff(Role::Accountant);
        $this->postJson(route('api.reception.devices.register', [], false), ['name' => 'x', 'branch' => $this->mainBranch()->public_id, 'device_fingerprint' => hash('sha256', 'a')])->assertForbidden();
        $this->assertSame(0, ReceptionDevice::query()->count());
    }

    public function test_actor_header_is_validated_against_role_branch_and_activity(): void
    {
        $device = $this->device();
        $other = Branch::factory()->create();
        $elsewhere = $this->receptionist($other);
        $admin = $this->hospitalAdmin($other);
        $doctor = $this->actingAsDoctor();
        $inactive = $this->receptionist();
        $inactive->forceFill(['is_active' => false])->save();
        $ok = $this->receptionist();

        $this->withToken($this->deviceToken($device))->getJson(route('api.reception.bootstrap', [], false))->assertForbidden()->assertJsonPath('code', 'reception.actor_not_permitted');
        $this->asDevice($device, $elsewhere)->getJson(route('api.reception.bootstrap', [], false))->assertForbidden();
        $this->asDevice($device, $doctor)->getJson(route('api.reception.bootstrap', [], false))->assertForbidden();
        $this->asDevice($device, $inactive)->getJson(route('api.reception.bootstrap', [], false))->assertForbidden();
        $this->asDevice($device, $admin)->getJson(route('api.reception.bootstrap', [], false))->assertOk();
        $this->asDevice($device, $ok)->getJson(route('api.reception.bootstrap', [], false))->assertOk()->assertJsonPath('actor.public_id', $ok->public_id);
    }

    public function test_a_staff_bearer_token_or_session_never_passes_as_a_device(): void
    {
        $user = $this->actingAsStaff(Role::Receptionist);
        $staffToken = $user->createToken('staff')->plainTextToken;

        $this->withToken($staffToken)->withHeader('X-Actor-User', $user->public_id)->getJson(route('api.reception.bootstrap', [], false))->assertStatus(401);
        $this->withHeader('X-Actor-User', $user->public_id)->getJson(route('api.reception.bootstrap', [], false))->assertStatus(401);
    }

    public function test_revoked_device_gets_401_and_its_blocks_are_revoked(): void
    {
        $device = $this->device();
        $actor = $this->receptionist();
        $admin = $this->hospitalAdmin();
        $session = $this->openSession(20, 0, 0);
        $block = $this->leaseFor($device, $session, 5);

        $this->asDevice($device, $actor)->postJson(route('api.reception.devices.revoke', ['device' => $device->public_id], false))->assertForbidden();
        $this->asDevice($device, $admin)->postJson(route('api.reception.devices.revoke', ['device' => $device->public_id], false))->assertOk()->assertJsonPath('device.status', 'revoked');

        $this->assertSame(DeviceStatus::Revoked, $device->fresh()->status);
        $this->assertSame(0, $device->tokens()->count());
        $this->assertTrue(SerialBlock::query()->findOrFail($block->id)->isRevoked());
        $this->asDevice($device, $actor)->getJson(route('api.reception.bootstrap', [], false))->assertStatus(401);
    }

    public function test_device_tokens_are_tenant_scoped(): void
    {
        $device = $this->device();
        $actor = $this->receptionist();
        $token = $this->deviceToken($device);
        $this->asDevice($device, $actor)->getJson(route('api.reception.bootstrap', [], false))->assertOk();

        $this->asTenant('b');
        $this->withToken($token)->withHeader('X-Actor-User', $actor->public_id)->getJson(route('api.reception.bootstrap', [], false))->assertStatus(401);

        $this->assertTenantIsolated('reception_devices', fn () => $this->device());
    }
}
