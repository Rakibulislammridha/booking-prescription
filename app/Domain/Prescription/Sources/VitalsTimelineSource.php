<?php

declare(strict_types=1);

namespace App\Domain\Prescription\Sources;

use App\Domain\Patients\Data\TimelineCursor;
use App\Domain\Patients\Data\TimelineEntry;
use App\Domain\Patients\Sources\ModelTimelineSource;
use App\Models\Tenant\Patient;
use App\Models\Tenant\Vital;
use Illuminate\Support\Facades\Schema;

/** kind `vital`: one row per recorded set (BP / pulse / temp / weight in the subtitle). */
final class VitalsTimelineSource extends ModelTimelineSource
{
    public function kind(): string
    {
        return 'vital';
    }

    /** @return array<int, TimelineEntry> */
    public function entries(Patient $patient, ?TimelineCursor $cursor, int $limit): array
    {
        if (! Schema::connection('pgsql')->hasTable('vitals')) {
            return [];
        }

        return $this->applyCursor(Vital::query()->where('patient_id', $patient->id), 'recorded_at', $cursor)
            ->limit($limit)->get()
            ->map(function (Vital $v): TimelineEntry {
                $parts = array_filter([
                    $v->bp_systolic !== null ? "BP {$v->bp_systolic}/{$v->bp_diastolic}" : null,
                    $v->pulse_bpm !== null ? "P {$v->pulse_bpm}" : null,
                    $v->temperature_c !== null ? "T {$v->temperature_c}°C" : null,
                    $v->weight_kg !== null ? "W {$v->weight_kg} kg" : null,
                    $v->spo2_percent !== null ? "SpO2 {$v->spo2_percent}%" : null,
                ]);

                return new TimelineEntry(
                    kind: $this->kind(), id: $v->id, occurredAt: $v->recorded_at, title: 'Vitals', subtitle: implode(' · ', $parts) ?: null, ref: (string) $v->id,
                    meta: array_intersect_key($v->getAttributes(), array_flip(Vital::MEASUREMENTS)) + ['bmi' => $v->bmi, 'visit_id' => $v->visit_id, 'reviewed_by_doctor_at' => $v->reviewed_by_doctor_at?->toIso8601String()],
                );
            })->all();
    }
}
