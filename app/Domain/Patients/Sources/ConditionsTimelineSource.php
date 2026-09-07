<?php

declare(strict_types=1);

namespace App\Domain\Patients\Sources;

use App\Domain\Patients\Data\TimelineCursor;
use App\Domain\Patients\Data\TimelineEntry;
use App\Models\Tenant\Patient;
use App\Models\Tenant\PatientCondition;

final class ConditionsTimelineSource extends ModelTimelineSource
{
    public function kind(): string
    {
        return 'condition';
    }

    /** @return array<int, TimelineEntry> */
    public function entries(Patient $patient, ?TimelineCursor $cursor, int $limit): array
    {
        return $this->applyCursor(PatientCondition::query()->where('patient_id', $patient->id), 'created_at', $cursor)
            ->limit($limit)
            ->get()
            ->map(fn (PatientCondition $c) => new TimelineEntry(
                kind: $this->kind(),
                id: $c->id,
                occurredAt: $c->created_at,
                title: $c->condition_name,
                subtitle: $c->icd10_code,
                ref: (string) $c->id,
                meta: ['status' => $c->status->value, 'onset_date' => $c->onset_date?->toDateString(), 'resolved_date' => $c->resolved_date?->toDateString()],
            ))
            ->all();
    }
}
