<?php

declare(strict_types=1);

namespace App\Domain\Patients\Contracts;

use App\Domain\Patients\Data\TimelineCursor;
use App\Domain\Patients\Data\TimelineEntry;
use App\Models\Tenant\Patient;

/**
 * One contributor to the patient timeline (PRESCRIPTION.md §8). The Patients module ships documents, allergies,
 * conditions, medications and consents; Prescription registers visits/vitals/prescriptions, Billing invoices,
 * Serials appointments — each through `TimelineSourceRegistry::register()` in its own ServiceProvider.
 *
 * Contract: return at most $limit entries strictly OLDER than $cursor (or the newest when null), sorted
 * occurred_at DESC, id DESC. A source whose table does not exist yet must return [] (Schema::hasTable guard).
 */
interface PatientTimelineSource
{
    /** Stable kind key used in cursors and on the wire: `document`, `visit`, `vital`, `prescription`, … */
    public function kind(): string;

    /** @return array<int, TimelineEntry> */
    public function entries(Patient $patient, ?TimelineCursor $cursor, int $limit): array;
}
