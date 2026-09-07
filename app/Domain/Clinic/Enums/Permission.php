<?php

declare(strict_types=1);

namespace App\Domain\Clinic\Enums;

/**
 * The single source of truth for permission names: <module>.<resource>.<action> (ARCHITECTURE §6.2).
 * Modules send new cases to the foundation owner; RolesAndPermissionsSeeder creates every case on every deploy.
 */
enum Permission: string
{
    // Clinic
    case ClinicUsersManage = 'clinic.users.manage';
    case ClinicBranchesManage = 'clinic.branches.manage';
    case ClinicDepartmentsManage = 'clinic.departments.manage';
    case ClinicSpecialtiesManage = 'clinic.specialties.manage';
    case ClinicDoctorsManage = 'clinic.doctors.manage';
    case ClinicHolidaysManage = 'clinic.holidays.manage';
    case ClinicLeavesManage = 'clinic.leaves.manage';
    case ClinicSettingsManage = 'clinic.settings.manage';
    case ClinicPadDesign = 'clinic.pad.design';

    // Scheduling
    case SchedulingSchedulesManage = 'scheduling.schedules.manage';

    // Serials
    case SerialsIssueCounter = 'serials.issue.counter';
    case SerialsSplitAdjust = 'serials.split.adjust';
    case SerialsReorder = 'serials.reorder';
    case SerialsCancel = 'serials.cancel';
    case SerialsTransfer = 'serials.transfer';
    case SerialsCapacityExtend = 'serials.capacity.extend';

    // Queue
    case QueueCallNext = 'queue.call-next';
    case QueueDelayBroadcast = 'queue.delay.broadcast';

    // Reception
    case ReceptionDevicesRegister = 'reception.devices.register';
    case ReceptionBlocksRevoke = 'reception.blocks.revoke';

    // Prescription
    case PrescriptionsWrite = 'prescriptions.write';
    case PrescriptionsVitalsRecord = 'prescriptions.vitals.record';
    case PrescriptionsViewAny = 'prescriptions.view.any';

    // Patients
    case PatientsView = 'patients.view';
    case PatientsCreate = 'patients.create';
    case PatientsUpdate = 'patients.update';
    case PatientsMerge = 'patients.merge';
    case PatientsExport = 'patients.export';

    // catalog
    case CatalogCustomBrandsManage = 'catalog.custom-brands.manage';

    // Billing
    case BillingPaymentsCollect = 'billing.payments.collect';
    case BillingRefundsIssue = 'billing.refunds.issue';
    case BillingInvoicesView = 'billing.invoices.view';
    case BillingDiscountsApprove = 'billing.discounts.approve';
    case BillingFeesOverride = 'billing.fees.override';
    case BillingReportsView = 'billing.reports.view';

    // Reports
    case ReportsView = 'reports.view';
    case ReportsFinancialView = 'reports.financial.view';
    case ReportsClinicalView = 'reports.clinical.view';
    case ReportsAllDoctorsView = 'reports.all-doctors.view';
    case ReportsExport = 'reports.export';

    // Notifications
    case NotificationsTemplatesManage = 'notifications.templates.manage';
    case NotificationsLogsView = 'notifications.logs.view';
    case NotificationsGatewaysManage = 'notifications.gateways.manage';
    case NotificationsSendTest = 'notifications.send.test';

    // SaaS (tenant-side subscription/settings screens)
    case SaasSettingsManage = 'saas.settings.manage';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
