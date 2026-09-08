<?php

declare(strict_types=1);

namespace App\Domain\SaaS\Actions\Onboarding;

use App\Domain\Audit\Enums\CentralAuditAction;
use App\Domain\SaaS\Data\OnboardingData;
use App\Domain\SaaS\Events\TenantOnboarded;
use App\Domain\SaaS\Services\CentralAudit;
use App\Domain\Tenancy\Actions\ProvisionTenant;
use App\Models\Central\Tenant;
use App\Tenancy\Facades\Tenancy;

/**
 * The wizard's last step. It does not re-implement provisioning: `ProvisionTenant` (ARCHITECTURE §4.4) already
 * writes the `public` rows, creates and migrates the schema, seeds roles, makes the first branch and the hospital
 * admin, and rolls all of it back if any part fails. This action is the SaaS-side wrapper — it decides the plan,
 * marks the onboarding progress, audits, and hands back where to send the browser.
 *
 * It asserts afterwards that no tenancy is left initialised: provisioning enters the new schema, and a central
 * request that returned with a tenant search path still set would be the exact bug the adversarial suite hunts.
 */
final class SignUpTenant
{
    public function __construct(
        private readonly ProvisionTenant $provision,
        private readonly CentralAudit $audit,
    ) {}

    public function handle(OnboardingData $data): Tenant
    {
        $tenant = $this->provision->handle($data->toProvisionData());

        if (Tenancy::check()) {
            Tenancy::end();                                        // belt and braces: central code never returns inside a tenant
        }

        $tenant->forceFill([
            'onboarding' => ['step' => 'done', 'demo_seeded' => $data->demo, 'completed_at' => now()->toIso8601String()],
        ])->save();

        $this->audit->record(CentralAuditAction::Create, $tenant, $tenant, null, [
            'slug' => $tenant->slug,
            'plan' => $data->planCode,
            'demo' => $data->demo,
        ]);

        TenantOnboarded::dispatch($tenant->id, 'https://'.$tenant->primaryHost().'/panel');

        return $tenant;
    }
}
