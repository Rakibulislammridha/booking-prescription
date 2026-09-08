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
    /**
     * The doctor id forced onto a scope that is restricted to one doctor but has no doctor to restrict TO.
     * `doctors.id` is a bigserial, so nothing is ever 0 and every query filtering on it returns no rows. A
     * scope like that is a MISCONFIGURATION (a login holding the doctor role whose `doctors.user_id` link was
     * never made, or was removed), and the only safe reading of it is "no data", never "all data".
     */
    private const NO_DOCTOR = 0;

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
        if ($this->deniesAll()) {
            return false;
        }

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

    /**
     * A scope restricted to a doctor it could not identify. `ReportScope::none()` is one of these, and so is a
     * login that holds `reports.view` and the doctor role but is not linked to a `doctors` row.
     *
     * This predicate exists because the obvious encoding of "restricted, but to nobody" — `allDoctors = false`
     * with `doctorId = null` — reads to every query object as NO DOCTOR FILTER AT ALL, since they all apply the
     * filter with `if ($filters->doctorId !== null)`. Left alone it hands such a login the WHOLE clinic, which
     * is the exact opposite of what restricting them was for.
     */
    public function deniesAll(): bool
    {
        return ! $this->allDoctors && $this->doctorId === null;
    }

    /**
     * Force the scope's doctor onto a filter set; a scoped user's report is always his own.
     *
     * A scope that denies everything is given the impossible doctor rather than a null one, so that a query run
     * directly against these filters — bypassing `ReportData`'s own gate — still returns nothing.
     */
    public function apply(ReportFilters $filters): ReportFilters
    {
        if ($this->deniesAll()) {
            return $filters->withDoctor(self::NO_DOCTOR);
        }

        return $this->allDoctors ? $filters : $filters->withDoctor($this->doctorId);
    }

    /**
     * A discriminator for the cache key of a payload whose CONTENT depends on the scope and not only on the
     * filters. The dashboard is the case that matters: it computes the money tile only for a scope that may see
     * money, so two users with identical filters but different financial rights must not share a cache row —
     * otherwise the first admin to open the dashboard warms a payload containing the clinic's takings and the
     * next viewer is handed it. Filters already carry the doctor, so only the capability flags belong here.
     */
    public function cacheVariant(): string
    {
        return sprintf('%d%d%d', (int) $this->financial, (int) $this->clinical, (int) $this->allDoctors);
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
