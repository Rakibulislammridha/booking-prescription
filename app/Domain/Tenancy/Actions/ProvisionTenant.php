<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Actions;

use App\Domain\Catalog\Search\MeilisearchIndexes;
use App\Domain\Clinic\Enums\Role;
use App\Domain\SaaS\Enums\BillingCycle;
use App\Domain\SaaS\Enums\DomainType;
use App\Domain\SaaS\Enums\DomainVerificationStatus;
use App\Domain\SaaS\Enums\SubscriptionStatus;
use App\Domain\SaaS\Enums\TenantStatus;
use App\Domain\Tenancy\Data\ProvisionTenantData;
use App\Domain\Tenancy\Events\TenantProvisioned;
use App\Domain\Tenancy\Exceptions\PlanNotFound;
use App\Domain\Tenancy\Exceptions\ProvisioningFailed;
use App\Domain\Tenancy\Exceptions\SlugReserved;
use App\Domain\Tenancy\Exceptions\SlugTaken;
use App\Domain\Tenancy\Rules\NotReservedSlug;
use App\Models\Central\Domain;
use App\Models\Central\Plan;
use App\Models\Central\Subscription;
use App\Models\Central\Tenant;
use App\Models\Tenant\Branch;
use App\Models\Tenant\User;
use App\Tenancy\Database\TenantMigrator;
use App\Tenancy\Facades\Tenancy;
use Carbon\CarbonImmutable;
use Database\Seeders\Tenant\DemoDataSeeder;
use Database\Seeders\Tenant\RolesAndPermissionsSeeder;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Throwable;

/**
 * public rows → CREATE SCHEMA → migrations + roles + first branch + admin (+ demo) → TenantProvisioned (ARCHITECTURE §4.4).
 */
final class ProvisionTenant
{
    public function __construct(
        private readonly TenantMigrator $migrator,
        private readonly Dispatcher $events,
    ) {}

    public function handle(ProvisionTenantData $data): Tenant
    {
        $plan = Plan::query()->where('code', $data->planCode)->first() ?? throw new PlanNotFound($data->planCode);

        if (! NotReservedSlug::isWellFormed($data->slug) || NotReservedSlug::isReserved($data->slug)) {
            throw new SlugReserved($data->slug);
        }

        if (Tenant::withTrashed()->where('slug', $data->slug)->exists()) {
            throw new SlugTaken($data->slug);
        }

        $tenant = DB::connection('pgsql')->transaction(fn () => $this->createCentralRows($data, $plan));

        try {
            $this->migrator->recreateSchema($tenant);

            Tenancy::run($tenant, function () use ($data, $tenant): void {
                $this->migrator->migrate();

                app(RolesAndPermissionsSeeder::class)->run();

                $branch = $this->createMainBranch($data);
                $this->createAdmin($data, $tenant, $branch);
                $this->ensureSearchIndexes($tenant);

                if ($data->demo) {
                    app(DemoDataSeeder::class)->run();
                }
            });

            $tenant->forceFill(['provisioned_at' => CarbonImmutable::now()])->save();
        } catch (Throwable $e) {
            $this->rollback($tenant);

            throw new ProvisioningFailed($data->slug, $e);
        }

        $this->events->dispatch(new TenantProvisioned($tenant));

        return $tenant;
    }

    private function createCentralRows(ProvisionTenantData $data, Plan $plan): Tenant
    {
        $id = $data->id ?? (int) DB::connection('pgsql')->scalar("select nextval(pg_get_serial_sequence('public.tenants', 'id'))");
        $schema = $data->schemaName ?? "tenant_{$id}";
        $now = CarbonImmutable::now();
        // The caller may override the plan's trial (the sign-up wizard passes the platform default `onboarding.trial_days`).
        $trialDays = max(0, $data->trialDays ?? $plan->trial_days);

        $tenant = new Tenant;
        $tenant->forceFill([
            'id' => $id,
            'name' => $data->name,
            'slug' => $data->slug,
            'schema_name' => $schema,
            'status' => TenantStatus::Trial,
            'timezone' => $data->timezone,
            'locale' => $data->locale,
            'currency' => 'BDT',
            'owner_name' => $data->ownerName,
            'owner_email' => $data->ownerEmail,
            'owner_mobile' => $data->ownerMobile,
            'trial_ends_at' => $now->addDays($trialDays),
            'onboarding' => ['step' => 'branches', 'demo_seeded' => $data->demo, 'completed_at' => null],
            'branding' => ['primary_color' => '#0f766e', 'logo_path' => null, 'favicon_path' => null, 'tagline_bn' => null, 'tagline_en' => null],
        ]);
        $tenant->save();

        Domain::query()->create([
            'tenant_id' => $tenant->id,
            'domain' => strtolower($data->slug.'.'.config('tenancy.central_domain')),
            'type' => DomainType::Subdomain,
            'is_primary' => true,
            'verification_status' => DomainVerificationStatus::Verified,
            'verification_token' => Str::random(40),
            'verified_at' => $now,
        ]);

        if ($data->customDomain !== null) {
            Domain::query()->create([
                'tenant_id' => $tenant->id,
                'domain' => strtolower($data->customDomain),
                'type' => DomainType::Custom,
                'is_primary' => false,
                'verification_status' => DomainVerificationStatus::Pending,
                'verification_token' => Str::random(40),
            ]);
        }

        $subscription = Subscription::query()->create([
            'tenant_id' => $tenant->id,
            'plan_id' => $plan->id,
            'status' => $trialDays > 0 ? SubscriptionStatus::Trialing : SubscriptionStatus::Active,
            'billing_cycle' => BillingCycle::Monthly,
            'price_paisa' => $plan->price_monthly_paisa,
            'current_period_start' => $now,
            'current_period_end' => $trialDays > 0 ? $now->addDays($trialDays) : $now->addMonth(),
            'trial_ends_at' => $trialDays > 0 ? $now->addDays($trialDays) : null,
        ]);

        $tenant->forceFill(['current_subscription_id' => $subscription->id])->save();

        return $tenant;
    }

    private function createMainBranch(ProvisionTenantData $data): Branch
    {
        $name = $data->branchName ?? $data->name;

        return Branch::query()->create([
            'name' => $name,
            'code' => strtoupper($data->branchCode ?? Str::substr(Str::slug($data->slug, ''), 0, 3) ?: 'MAIN'),
            'slug' => Str::slug($name) ?: 'main',
            'is_main' => true,
            'is_active' => true,
            'settings' => ['token_slip_width_mm' => 58, 'display_mode' => ['voice' => true, 'languages' => ['bn', 'en']]],
        ]);
    }

    private function createAdmin(ProvisionTenantData $data, Tenant $tenant, Branch $branch): User
    {
        $user = User::query()->create([
            'tenant_id' => $tenant->id,
            'name' => $data->adminName ?? $data->ownerName,
            'email' => $data->adminEmail,
            'password' => Hash::make($data->adminPassword),
            'default_branch_id' => $branch->id,
            'locale' => $data->locale,
            'is_active' => true,
            'email_verified_at' => CarbonImmutable::now(),
        ]);

        $user->assignRole(Role::HospitalAdmin->value);

        return $user;
    }

    /** Catalog module: creates t{id}_patients / t{id}_custom_brands with their settings (ARCHITECTURE §8.6). */
    private function ensureSearchIndexes(Tenant $tenant): void
    {
        if (config('scout.driver') !== 'meilisearch') {
            return;
        }

        app(MeilisearchIndexes::class)->ensureTenantIndexes($tenant);
    }

    private function rollback(Tenant $tenant): void
    {
        try {
            if (Tenancy::check()) {
                Tenancy::end();
            }

            DB::connection('pgsql')->statement("drop schema if exists \"{$tenant->schema_name}\" cascade");
            Subscription::query()->where('tenant_id', $tenant->id)->delete();
            Domain::query()->where('tenant_id', $tenant->id)->delete();
            $tenant->forceDelete();
        } catch (Throwable) {
            // best effort; the original failure is rethrown by the caller
        }
    }
}
