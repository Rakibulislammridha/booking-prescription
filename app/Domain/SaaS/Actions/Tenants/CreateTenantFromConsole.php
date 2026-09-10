<?php

declare(strict_types=1);

namespace App\Domain\SaaS\Actions\Tenants;

use App\Domain\Audit\Enums\CentralAuditAction;
use App\Domain\SaaS\Data\ConsoleTenantData;
use App\Domain\SaaS\Data\CredentialReveal;
use App\Domain\SaaS\Enums\SubscriptionStatus;
use App\Domain\SaaS\Enums\TenantStatus;
use App\Domain\SaaS\Events\TenantOnboarded;
use App\Domain\SaaS\Services\CentralAudit;
use App\Domain\SaaS\Services\StaffCredentials;
use App\Domain\SaaS\Services\SubscriptionLifecycle;
use App\Domain\SaaS\Services\TenantLinks;
use App\Domain\Tenancy\Actions\ProvisionTenant;
use App\Models\Central\Tenant;
use App\Models\Tenant\User;
use App\Tenancy\Facades\Tenancy;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Onboarding a clinic from the console (BRIEF §5.M) — the operator's twin of `Onboarding\SignUpTenant`.
 *
 * It does not provision anything itself: `ProvisionTenant` (ARCHITECTURE §4.4) is the one path that creates a
 * schema, and it is called with exactly the data the public wizard would build. What this action adds is what an
 * operator knows that a self-serving customer does not — a Bangla name, a hand-negotiated trial, notes — and the
 * credential hand-off: either the password the operator typed, or a set-password link minted on the clinic's own
 * host and shown once.
 */
final class CreateTenantFromConsole
{
    public function __construct(
        private readonly ProvisionTenant $provision,
        private readonly SubscriptionLifecycle $lifecycle,
        private readonly StaffCredentials $credentials,
        private readonly TenantLinks $links,
        private readonly CentralAudit $audit,
    ) {}

    /** @return array{tenant: Tenant, reveal: CredentialReveal} */
    public function handle(ConsoleTenantData $data, ?int $superAdminId = null): array
    {
        $password = $data->adminPassword ?? $this->credentials->temporaryPassword();
        $tenant = $this->provision->handle($data->toProvisionData($password));

        if (Tenancy::check()) {
            Tenancy::end();                                        // central code never returns inside a tenant
        }

        if ($data->trialDays !== null) {
            $this->applyTrial($tenant, $data->trialDays);
        }

        $tenant->forceFill([
            'branding' => array_replace($tenant->branding, ['name_bn' => $data->nameBn]),
            'platform_notes' => $data->notes,
            'onboarding' => ['step' => 'done', 'demo_seeded' => $data->demo, 'completed_at' => CarbonImmutable::now()->toIso8601String()],
        ])->save();

        /** @var CredentialReveal $reveal */
        $reveal = Tenancy::run($tenant, function () use ($tenant, $data, $password): CredentialReveal {
            $admin = User::query()->where('email', $data->adminEmail)->firstOrFail();
            $admin->forceFill(['must_change_password' => true])->save();

            return $data->adminPassword === null
                ? $this->credentials->setPasswordLink($tenant, $admin)
                : $this->credentials->passwordReveal($admin, $password);
        });

        $this->audit->record(CentralAuditAction::Create, $tenant, $tenant, null, [
            'slug' => $tenant->slug,
            'plan' => $data->planCode,
            'trial_days' => $data->trialDays,
            'demo' => $data->demo,
            'custom_domain' => $data->customDomain,
            'admin_email' => $data->adminEmail,
            'credential' => $reveal->kind,
            'source' => 'console',
        ], $superAdminId);

        TenantOnboarded::dispatch($tenant->id, $this->links->panel($tenant));

        return ['tenant' => $tenant->refresh(), 'reveal' => $reveal];
    }

    /**
     * A hand-negotiated trial overrides the plan's default. Zero days means the clinic starts on a paid period at
     * once — `active`, with the first month already running — which is what "they have already paid" looks like.
     */
    private function applyTrial(Tenant $tenant, int $days): void
    {
        $subscription = $this->lifecycle->current($tenant);
        $now = CarbonImmutable::now();

        DB::connection('pgsql')->transaction(function () use ($tenant, $subscription, $days, $now): void {
            if ($days > 0) {
                $tenant->forceFill(['status' => TenantStatus::Trial, 'trial_ends_at' => $now->addDays($days)])->save();
                $subscription?->forceFill([
                    'status' => SubscriptionStatus::Trialing,
                    'trial_ends_at' => $now->addDays($days),
                    'current_period_end' => $now->addDays($days),
                ])->save();

                return;
            }

            $tenant->forceFill(['status' => TenantStatus::Active, 'trial_ends_at' => null])->save();
            $subscription?->forceFill([
                'status' => SubscriptionStatus::Active,
                'trial_ends_at' => null,
                'current_period_start' => $now,
                'current_period_end' => $this->lifecycle->nextPeriodEnd($subscription, $now),
            ])->save();
        });
    }
}
