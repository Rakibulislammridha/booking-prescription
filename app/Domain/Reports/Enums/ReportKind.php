<?php

declare(strict_types=1);

namespace App\Domain\Reports\Enums;

/**
 * The report families of BRIEF §5.L. The value is the URL segment, the Inertia page name and the export
 * `report` column, so there is exactly one spelling of "which report is this" in the whole module.
 */
enum ReportKind: string
{
    case Dashboard = 'dashboard';
    case Appointments = 'appointments';
    case WaitTimes = 'wait-times';
    case Revenue = 'revenue';
    case Patients = 'patients';
    case Clinical = 'clinical';
    case PeakHours = 'peak-hours';

    /** Money reports need `reports.financial.view`; clinical ones need `reports.clinical.view`. */
    public function isFinancial(): bool
    {
        return $this === self::Revenue;
    }

    public function isClinical(): bool
    {
        return $this === self::Clinical;
    }

    /** The Inertia component under `panel/Pages/Reports/`. */
    public function page(): string
    {
        return 'Reports/'.str_replace(' ', '', ucwords(str_replace('-', ' ', $this->value)));
    }

    public function titleKey(): string
    {
        return 'reports.'.str_replace('-', '_', $this->value).'.title';
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
