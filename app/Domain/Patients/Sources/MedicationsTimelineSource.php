<?php

declare(strict_types=1);

namespace App\Domain\Patients\Sources;

use App\Domain\Patients\Data\TimelineCursor;
use App\Domain\Patients\Data\TimelineEntry;
use App\Models\Tenant\Patient;
use App\Models\Tenant\PatientMedication;

final class MedicationsTimelineSource extends ModelTimelineSource
{
    public function kind(): string
    {
        return 'medication';
    }

    /** @return array<int, TimelineEntry> */
    public function entries(Patient $patient, ?TimelineCursor $cursor, int $limit): array
    {
        return $this->applyCursor(PatientMedication::query()->where('patient_id', $patient->id), 'created_at', $cursor)
            ->limit($limit)
            ->get()
            ->map(fn (PatientMedication $m) => new TimelineEntry(
                kind: $this->kind(),
                id: $m->id,
                occurredAt: $m->created_at,
                title: $m->brand_name !== null ? "{$m->brand_name} ({$m->generic_name})" : $m->generic_name,
                subtitle: $m->dose_text,
                ref: (string) $m->id,
                meta: ['source' => $m->source->value, 'is_active' => $m->is_active, 'started_on' => $m->started_on?->toDateString(), 'ended_on' => $m->ended_on?->toDateString()],
            ))
            ->all();
    }
}
