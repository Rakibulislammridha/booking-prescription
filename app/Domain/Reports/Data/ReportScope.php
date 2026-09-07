<?php

declare(strict_types=1);

namespace App\Domain\Reports\Data;

use App\Domain\Reports\Enums\ReportKind;

/**
 * What this staff member is allowed to see (BRIEF §5.N "role-scoped data access"). The scope is resolved ONCE
 * from the user's permissions and then forced onto the filters, so a doctor cannot widen his own report by
 * editing `?doctor=` in the address bar: `ReportScopeResolver::apply()` overwrites the doctor filter rather
 * than validating it.
 *
 *  - hospital admin  → everything, every doctor
 *  - accountant      → money, every doctor, no clinical detail
 *  - doctor          → his own clinical numbers, no clinic revenue
 */
final readonly class ReportScope
{
    public function __construct(
        public bool $financial,
        public bool $clinical,
        public bool $allDoctors,
        public bool $canExport,
        public ?int $doctorId = null,
    ) {}

    public static function none(): self
    {
        return new self(false, false, false, false, null);
    }

    public function allows(ReportKind $kind): bool
    {
        return match (true) {
            $kind->isFinancial() => $this->financial,
            $kind->isClinical() => $this->clinical,
            default => true,
        };
    }

    /**
     * Reports this scope may open, in navigation order.
     *
     * @return array<int, ReportKind>
     */
    public function visibleReports(): array
    {
        return array_values(array_filter(ReportKind::cases(), fn (ReportKind $k): bool => $this->allows($k)));
    }

    /** Force the scope's doctor onto a filter set; a scoped user's report is always his own. */
    public function apply(ReportFilters $filters): ReportFilters
    {
        return $this->allDoctors ? $filters : $filters->withDoctor($this->doctorId);
    }

    /** @return array<string, mixed> the `scope` prop every report page receives */
    public function toArray(): array
    {
        return [
            'financial' => $this->financial,
            'clinical' => $this->clinical,
            'all_doctors' => $this->allDoctors,
            'can_export' => $this->canExport,
            'doctor_id' => $this->doctorId,
            'reports' => array_map(fn (ReportKind $k): string => $k->value, $this->visibleReports()),
        ];
    }
}
