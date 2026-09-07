<?php

declare(strict_types=1);

namespace Tests\Feature\Prescription;

use App\Domain\Clinic\Enums\Role;
use App\Domain\Prescription\Services\PrescriptionChannelGuard;
use App\Domain\Queue\TenantChannel;
use App\Models\Tenant\Prescription;
use App\Models\Tenant\User;
use App\Tenancy\Facades\Tenancy;
use Tests\TestCase;

/**
 * REALTIME.md §2 / PRESCRIPTION.md §7.5 — the private channel the writer listens on for `pdf.ready`. Whoever may
 * subscribe learns that a named prescription exists and when it was finished, so the guard is the read policy.
 */
final class PrescriptionChannelTest extends TestCase
{
    use PrescriptionTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->asTenant('a');
    }

    public function test_the_channel_name_is_built_from_public_ids_only(): void
    {
        [$rx] = $this->issuedWithContent();
        $name = TenantChannel::prescription($rx)->name;

        // Every segment is a ULID: a bigint in a channel name leaks row counts to anyone who can read the client.
        $this->assertSame('private-tenant.'.Tenancy::current()?->public_id.'.prescription.'.$rx->public_id, $name);
        $this->assertMatchesRegularExpression('/^private-tenant\.[0-9A-HJKMNP-TV-Z]{26}\.prescription\.[0-9A-HJKMNP-TV-Z]{26}$/', $name);
    }

    public function test_only_a_user_who_may_read_the_prescription_may_subscribe(): void
    {
        [$rx] = $this->issuedWithContent();
        $guard = app(PrescriptionChannelGuard::class);
        $tenant = (string) Tenancy::current()?->public_id;
        $owner = User::query()->findOrFail((int) auth('web')->id());

        $this->assertTrue($guard->view($owner, $tenant, $rx->public_id));

        // Wrong tenant segment, unknown prescription, no user, inactive user, unrelated role.
        $this->assertFalse($guard->view($owner, 'not-this-tenant', $rx->public_id));
        $this->assertFalse($guard->view($owner, $tenant, '01ZZZZZZZZZZZZZZZZZZZZZZZZ'));
        $this->assertFalse($guard->view(null, $tenant, $rx->public_id));

        $accountant = $this->actingAsStaff(Role::Accountant);
        $this->assertFalse($guard->view($accountant, $tenant, $rx->public_id));

        $owner->forceFill(['is_active' => false])->save();
        $this->assertFalse($guard->view($owner->fresh(), $tenant, $rx->public_id));
    }

    public function test_a_prescription_of_another_tenant_is_invisible_on_this_tenants_channel(): void
    {
        [$rx] = $this->issuedWithContent();
        $publicId = $rx->public_id;

        $this->asTenant('b');
        $staff = $this->actingAsStaff(Role::HospitalAdmin);

        $this->assertNull(Prescription::query()->where('public_id', $publicId)->first());
        $this->assertFalse(app(PrescriptionChannelGuard::class)->view($staff, (string) Tenancy::current()?->public_id, $publicId));
    }
}
