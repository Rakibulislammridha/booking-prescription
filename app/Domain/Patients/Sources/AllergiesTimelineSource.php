<?php

declare(strict_types=1);

namespace App\Domain\Patients\Sources;

use App\Domain\Patients\Data\TimelineCursor;
use App\Domain\Patients\Data\TimelineEntry;
use App\Models\Tenant\Patient;
use App\Models\Tenant\PatientAllergy;

final class AllergiesTimelineSource extends ModelTimelineSource
{
    public function kind(): string
    {
        return 'allergy';
    }

    /** @return array<int, TimelineEntry> */
    public function entries(Patient $patient, ?TimelineCursor $cursor, int $limit): array
    {
        return $this->applyCursor(PatientAllergy::query()->where('patient_id', $patient->id), 'created_at', $cursor)
            ->limit($limit)
            ->get()
            ->map(fn (PatientAllergy $a) => new TimelineEntry(
                kind: $this->kind(),
                id: $a->id,
                occurredAt: $a->created_at,
                title: $a->allergen_name,
                subtitle: $a->reaction,
                ref: (string) $a->id,
                meta: ['allergen_type' => $a->allergen_type->value, 'severity' => $a->severity->value, 'is_active' => $a->is_active],
            ))
            ->all();
    }
}
