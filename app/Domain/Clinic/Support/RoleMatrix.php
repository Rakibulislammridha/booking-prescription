<?php

declare(strict_types=1);

namespace App\Domain\Clinic\Support;

use App\Domain\Clinic\Enums\Permission;
use App\Domain\Clinic\Enums\Role;

/**
 * Role → permission matrix synced by RolesAndPermissionsSeeder. Hospital Admin holds every permission.
 */
final class RoleMatrix
{
    /** @return array<int, Permission> */
    public static function permissionsFor(Role $role): array
    {
        return match ($role) {
            Role::HospitalAdmin => Permission::cases(),
            Role::Doctor => [
                Permission::SchedulingSchedulesManage,
                Permission::ClinicLeavesManage,
                Permission::ClinicPadDesign,
                Permission::SerialsSplitAdjust,
                Permission::SerialsReorder,
                Permission::SerialsCapacityExtend,
                Permission::QueueCallNext,
                Permission::QueueDelayBroadcast,
                Permission::PrescriptionsWrite,
                Permission::PrescriptionsVitalsRecord,
                Permission::PatientsView,
                Permission::CatalogCustomBrandsManage,
                Permission::ReportsView,
                Permission::ReportsClinicalView,
                Permission::ReportsExport,
            ],
            Role::Receptionist => [
                Permission::SerialsIssueCounter,
                Permission::SerialsReorder,
                Permission::SerialsCancel,
                Permission::SerialsTransfer,
                Permission::SerialsCapacityExtend,
                Permission::QueueCallNext,
                Permission::ReceptionDevicesRegister,
                Permission::PrescriptionsVitalsRecord,
                Permission::PatientsView,
                Permission::PatientsCreate,
                Permission::PatientsUpdate,
                Permission::BillingPaymentsCollect,
                Permission::BillingInvoicesView,
            ],
            Role::Accountant => [
                Permission::BillingPaymentsCollect,
                Permission::BillingRefundsIssue,
                Permission::BillingInvoicesView,
                Permission::BillingDiscountsApprove,
                Permission::BillingReportsView,
                Permission::ReportsView,
                Permission::ReportsFinancialView,
                Permission::ReportsAllDoctorsView,
                Permission::ReportsExport,
                Permission::PatientsView,
                // The outbound log is how an accountant reconciles what the clinic was charged for: SMS credits
                // are money. Sending (retry, gateway test) and the credentials themselves stay with the admin.
                Permission::NotificationsLogsView,
            ],
        };
    }

    /** @return array<string, array<int, string>> role value → permission values */
    public static function toArray(): array
    {
        $matrix = [];

        foreach (Role::cases() as $role) {
            $matrix[$role->value] = array_map(fn (Permission $p) => $p->value, self::permissionsFor($role));
        }

        return $matrix;
    }
}
