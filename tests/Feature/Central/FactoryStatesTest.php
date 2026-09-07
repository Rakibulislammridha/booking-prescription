<?php

declare(strict_types=1);

namespace Tests\Feature\Central;

use App\Domain\Clinic\Enums\Role;
use App\Domain\SaaS\Enums\UsageMetric;
use Database\Factories\Central as C;
use Database\Factories\Tenant as T;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Every factory definition AND every named state must insert repeatedly under the real CHECK/UNIQUE constraints
 * (seven times each: `unique()` pools and constant keys used to exhaust on the 2nd/7th row).
 */
final class FactoryStatesTest extends TestCase
{
    public function test_central_factories_and_states(): void
    {
        $cases = [
            'Tenant' => fn () => C\TenantFactory::new()->create(),
            'Tenant.active' => fn () => C\TenantFactory::new()->active()->create(),
            'Tenant.suspended' => fn () => C\TenantFactory::new()->suspended()->create(),
            'Tenant.cancelled' => fn () => C\TenantFactory::new()->cancelled()->create(),
            'Domain' => fn () => C\DomainFactory::new()->create(),
            'Domain.verified' => fn () => C\DomainFactory::new()->verified()->create(),
            'Domain.subdomain' => fn () => C\DomainFactory::new()->subdomain()->create(),
            'Plan' => fn () => C\PlanFactory::new()->create(),
            'Plan.addon' => fn () => C\PlanFactory::new()->addon()->create(),
            'PlanFeature' => fn () => C\PlanFeatureFactory::new()->create(),
            'Subscription' => fn () => C\SubscriptionFactory::new()->create(),
            'Subscription.active' => fn () => C\SubscriptionFactory::new()->active()->create(),
            'SubscriptionInvoice' => fn () => C\SubscriptionInvoiceFactory::new()->create(),
            'SubscriptionInvoice.issued' => fn () => C\SubscriptionInvoiceFactory::new()->issued()->create(),
            'SubscriptionPayment' => fn () => C\SubscriptionPaymentFactory::new()->create(),
            'SubscriptionPayment.succeeded' => fn () => C\SubscriptionPaymentFactory::new()->succeeded()->create(),
            'SuperAdmin' => fn () => C\SuperAdminFactory::new()->create(),
            'SuperAdmin.inactive' => fn () => C\SuperAdminFactory::new()->inactive()->create(),
            'UsageCounter' => fn () => C\UsageCounterFactory::new()->create(),
            'UsageCounter.gauge' => fn () => C\UsageCounterFactory::new()->gauge(UsageMetric::Doctors)->create(),
            'TenantBackup' => fn () => C\TenantBackupFactory::new()->create(),
            'TenantBackup.completed' => fn () => C\TenantBackupFactory::new()->completed()->create(),
            'CatalogReconciliationReport' => fn () => C\CatalogReconciliationReportFactory::new()->create(),
            'CustomBrandPromotion' => fn () => C\CustomBrandPromotionFactory::new()->create(),
            'AuditLogCentral' => fn () => C\AuditLogCentralFactory::new()->create(),
            'ImpersonationToken' => fn () => C\ImpersonationTokenFactory::new()->create(),
            'ImpersonationToken.consumed' => fn () => C\ImpersonationTokenFactory::new()->consumed()->create(),
        ];

        $failures = [];
        foreach ($cases as $name => $make) {
            for ($i = 0; $i < 7; $i++) {
                try {
                    $m = DB::transaction(fn () => $make());   // savepoint: one failure must not abort the others
                    $this->assertTrue($m->exists);
                    $this->assertNotNull($m->fresh());
                } catch (\Throwable $e) {
                    $failures[] = $name.' #'.$i.': '.$e->getMessage();
                    break;
                }
            }
        }

        $this->assertSame([], $failures, implode("\n", $failures));
    }

    public function test_tenant_factories_and_states_inside_tenant_a(): void
    {
        $this->asTenant('a');

        $cases = [
            'Branch' => fn () => T\BranchFactory::new()->create(),
            'Branch.main' => fn () => T\BranchFactory::new()->main()->create(),
            'Department' => fn () => T\DepartmentFactory::new()->create(),
            'Specialty' => fn () => T\SpecialtyFactory::new()->create(),
            'User' => fn () => T\UserFactory::new()->create(),
            'User.withRole' => fn () => T\UserFactory::new()->withRole(Role::Doctor)->create(),
            'User.inactive' => fn () => T\UserFactory::new()->inactive()->create(),
            'Doctor' => fn () => T\DoctorFactory::new()->create(),
            'Doctor.complete' => fn () => T\DoctorFactory::new()->complete()->create(),
            'DoctorProfile' => fn () => T\DoctorProfileFactory::new()->create(),
            'DoctorSpecialty' => fn () => T\DoctorSpecialtyFactory::new()->create(),
            'DoctorSpecialty.primary' => fn () => T\DoctorSpecialtyFactory::new()->primary()->create(),
            'DoctorPadSetting' => fn () => T\DoctorPadSettingFactory::new()->create(),
            'DoctorPadSetting.preprinted' => fn () => T\DoctorPadSettingFactory::new()->preprinted()->create(),
            'Holiday' => fn () => T\HolidayFactory::new()->create(),
            'DoctorLeave' => fn () => T\DoctorLeaveFactory::new()->create(),
            'DoctorLeave.emergency' => fn () => T\DoctorLeaveFactory::new()->emergency()->create(),
            'Setting' => fn () => T\SettingFactory::new()->create(),
            'AuditLog' => fn () => T\AuditLogFactory::new()->create(),
        ];

        $failures = [];
        foreach ($cases as $name => $make) {
            for ($i = 0; $i < 7; $i++) {
                try {
                    $m = DB::transaction(fn () => $make());   // savepoint: one failure must not abort the others
                    $this->assertTrue($m->exists);
                    $this->assertNotNull($m->fresh());
                    if (method_exists($m, 'usesPublicId') && $m->usesPublicId()) {
                        $this->assertMatchesRegularExpression('/^[0-9A-HJKMNP-TV-Z]{26}$/', (string) $m->getAttribute('public_id'));
                    }
                } catch (\Throwable $e) {
                    $failures[] = $name.' #'.$i.': '.$e->getMessage();
                    break;
                }
            }
        }

        $this->assertSame([], $failures, implode("\n", $failures));
        $this->assertSame(self::TENANT_A, DB::scalar('show search_path'));
    }
}
