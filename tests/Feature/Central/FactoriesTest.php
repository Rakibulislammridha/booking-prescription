<?php

declare(strict_types=1);

namespace Tests\Feature\Central;

use App\Models\Central\Tenant;
use Database\Factories\Central as C;
use Database\Factories\Tenant as T;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class FactoriesTest extends TestCase
{
    /** @param  class-string<Factory<covariant Model>>  $factory */
    #[DataProvider('centralFactories')]
    public function test_every_central_factory_creates_a_row(string $factory): void
    {
        $instance = $factory::new()->create();

        $this->assertTrue($instance->exists);
        $this->assertNotNull($instance->fresh());
    }

    /** @return array<string, array{0: class-string<Factory<covariant Model>>}> */
    public static function centralFactories(): array
    {
        return [
            'Tenant' => [C\TenantFactory::class], 'Domain' => [C\DomainFactory::class], 'Plan' => [C\PlanFactory::class], 'PlanFeature' => [C\PlanFeatureFactory::class],
            'Subscription' => [C\SubscriptionFactory::class], 'SubscriptionInvoice' => [C\SubscriptionInvoiceFactory::class], 'SubscriptionPayment' => [C\SubscriptionPaymentFactory::class],
            'SuperAdmin' => [C\SuperAdminFactory::class], 'UsageCounter' => [C\UsageCounterFactory::class], 'TenantBackup' => [C\TenantBackupFactory::class],
            'CatalogReconciliationReport' => [C\CatalogReconciliationReportFactory::class], 'CustomBrandPromotion' => [C\CustomBrandPromotionFactory::class],
            'AuditLogCentral' => [C\AuditLogCentralFactory::class], 'ImpersonationToken' => [C\ImpersonationTokenFactory::class],
        ];
    }

    /** @param  class-string<Factory<covariant Model>>  $factory */
    #[DataProvider('tenantFactories')]
    public function test_every_tenant_factory_creates_a_row_inside_the_active_tenant(string $factory): void
    {
        $this->asTenant('a');
        $instance = $factory::new()->create();

        $this->assertTrue($instance->exists);
        $this->assertNotNull($instance->fresh());

        if (method_exists($instance, 'usesPublicId') && $instance->usesPublicId()) {
            $this->assertSame(26, strlen((string) $instance->getAttribute('public_id')));
        }
    }

    /** @return array<string, array{0: class-string<Factory<covariant Model>>}> */
    public static function tenantFactories(): array
    {
        return [
            'Branch' => [T\BranchFactory::class], 'Department' => [T\DepartmentFactory::class], 'Specialty' => [T\SpecialtyFactory::class], 'User' => [T\UserFactory::class],
            'Doctor' => [T\DoctorFactory::class], 'DoctorProfile' => [T\DoctorProfileFactory::class], 'DoctorSpecialty' => [T\DoctorSpecialtyFactory::class],
            'DoctorPadSetting' => [T\DoctorPadSettingFactory::class], 'Holiday' => [T\HolidayFactory::class], 'DoctorLeave' => [T\DoctorLeaveFactory::class],
            'Setting' => [T\SettingFactory::class], 'AuditLog' => [T\AuditLogFactory::class],
        ];
    }

    public function test_public_ids_are_ulids_and_never_uuids(): void
    {
        $tenant = Tenant::factory()->create();

        $this->assertMatchesRegularExpression('/^[0-9A-HJKMNP-TV-Z]{26}$/', $tenant->public_id);
    }
}
