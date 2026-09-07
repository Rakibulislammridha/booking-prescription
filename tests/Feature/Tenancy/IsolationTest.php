<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Models\Central\AuditLogCentral;
use App\Models\Central\CatalogReconciliationReport;
use App\Models\Central\CustomBrandPromotion;
use App\Models\Central\Domain;
use App\Models\Central\ImpersonationToken;
use App\Models\Central\PersonalAccessToken;
use App\Models\Central\Plan;
use App\Models\Central\PlanFeature;
use App\Models\Central\Subscription;
use App\Models\Central\SubscriptionInvoice;
use App\Models\Central\SubscriptionPayment;
use App\Models\Central\SuperAdmin;
use App\Models\Central\Tenant;
use App\Models\Central\TenantBackup;
use App\Models\Central\UsageCounter;
use App\Models\Tenant\Branch;
use App\Models\Tenant\Department;
use App\Models\Tenant\Doctor;
use App\Models\Tenant\Holiday;
use App\Models\Tenant\Role;
use App\Models\Tenant\Setting;
use App\Models\Tenant\Specialty;
use App\Models\Tenant\User;
use App\Tenancy\Exceptions\TenancyNotInitialized;
use App\Tenancy\Facades\Tenancy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class IsolationTest extends TestCase
{
    public function test_branches_are_isolated_between_tenants(): void
    {
        $this->assertTenantIsolated('branches', fn () => Branch::factory()->count(2)->create());
    }

    public function test_users_departments_specialties_doctors_and_settings_are_isolated(): void
    {
        $this->assertTenantIsolated('departments', fn () => Department::factory()->create());
        $this->assertTenantIsolated('specialties', fn () => Specialty::factory()->create());
        $this->assertTenantIsolated('doctors', fn () => Doctor::factory()->complete()->create());
        $this->assertTenantIsolated('holidays', fn () => Holiday::factory()->create());
        $this->assertTenantIsolated('settings', fn () => Setting::factory()->create());
    }

    public function test_the_provisioned_admin_user_is_only_visible_in_its_own_tenant(): void
    {
        $this->asTenant('a');
        $this->assertSame(1, User::query()->where('email', 'admin@test-a.test')->count());

        $this->asTenant('b');
        $this->assertSame(0, User::query()->where('email', 'admin@test-a.test')->count());
        $this->assertSame(1, User::query()->where('email', 'admin@test-b.test')->count());
    }

    /** @param  class-string<Model>  $model */
    #[DataProvider('tenantModels')]
    public function test_tenant_models_throw_without_tenancy(string $model): void
    {
        $this->assertFalse(Tenancy::check());
        $this->expectException(TenancyNotInitialized::class);

        $model::query()->count();
    }

    /** @return array<string, array{0: class-string<Model>}> */
    public static function tenantModels(): array
    {
        return [
            'Branch' => [Branch::class], 'Department' => [Department::class], 'Specialty' => [Specialty::class], 'User' => [User::class],
            'Doctor' => [Doctor::class], 'Holiday' => [Holiday::class], 'Setting' => [Setting::class], 'Role' => [Role::class],
        ];
    }

    public function test_tenant_models_cannot_be_written_without_tenancy(): void
    {
        $this->expectException(TenancyNotInitialized::class);

        $branch = new Branch(['name' => 'x', 'code' => 'X', 'slug' => 'x']);
        $branch->save();
    }

    public function test_a_foreign_tenant_id_can_never_be_inserted_while_a_tenant_is_active(): void
    {
        $this->asTenant('a');

        $this->expectException(LogicException::class);

        User::factory()->create(['tenant_id' => 9002]);
    }

    public function test_tenant_id_is_filled_and_scoped_from_the_active_tenant(): void
    {
        $this->asTenant('a');
        $user = User::factory()->create();

        $this->assertSame(9001, $user->tenant_id);
        $this->assertStringContainsString('"users"."tenant_id" = ?', User::query()->toSql());
    }

    /** @param  class-string<Model>  $model */
    #[DataProvider('centralModels')]
    public function test_central_models_qualify_public_and_resolve_under_a_tenant_search_path(string $model, string $table): void
    {
        $this->assertSame('public.'.$table, (new $model)->getTable());

        $this->asTenant('a');
        $this->assertSame(self::TENANT_A, DB::scalar('show search_path'));
        $this->assertGreaterThanOrEqual(0, $model::query()->count());   // would fail without the public. prefix (no fallback)
        $this->assertStringContainsString('"public"."'.$table.'"', $model::query()->toSql());
    }

    /** @return array<string, array{0: class-string<Model>, 1: string}> */
    public static function centralModels(): array
    {
        return [
            'Tenant' => [Tenant::class, 'tenants'], 'Domain' => [Domain::class, 'domains'], 'Plan' => [Plan::class, 'plans'],
            'PlanFeature' => [PlanFeature::class, 'plan_features'], 'Subscription' => [Subscription::class, 'subscriptions'],
            'SubscriptionInvoice' => [SubscriptionInvoice::class, 'subscription_invoices'], 'SubscriptionPayment' => [SubscriptionPayment::class, 'subscription_payments'],
            'SuperAdmin' => [SuperAdmin::class, 'super_admins'], 'UsageCounter' => [UsageCounter::class, 'usage_counters'],
            'TenantBackup' => [TenantBackup::class, 'tenant_backups'], 'CatalogReconciliationReport' => [CatalogReconciliationReport::class, 'catalog_reconciliation_reports'],
            'CustomBrandPromotion' => [CustomBrandPromotion::class, 'custom_brand_promotions'], 'AuditLogCentral' => [AuditLogCentral::class, 'audit_logs_central'],
            'PersonalAccessToken' => [PersonalAccessToken::class, 'personal_access_tokens'], 'ImpersonationToken' => [ImpersonationToken::class, 'impersonation_tokens'],
        ];
    }

    public function test_bare_tenant_table_names_do_not_resolve_centrally(): void
    {
        $this->assertSame('public', DB::scalar('show search_path'));
        $this->assertFalse((bool) DB::scalar("select exists (select 1 from pg_class c join pg_namespace n on n.oid = c.relnamespace where n.nspname = current_schema() and c.relname = 'branches')"));
        $this->assertThrows(fn () => DB::transaction(fn () => DB::table('branches')->count()), QueryException::class);
    }
}
