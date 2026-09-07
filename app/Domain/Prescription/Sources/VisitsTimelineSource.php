<?php

declare(strict_types=1);

namespace App\Domain\Prescription\Sources;

use App\Domain\Patients\Data\TimelineCursor;
use App\Domain\Patients\Data\TimelineEntry;
use App\Domain\Patients\Sources\ModelTimelineSource;
use App\Models\Tenant\Patient;
use App\Models\Tenant\Visit;
use Illuminate\Support\Facades\Schema;

/** kind `visit` (PRESCRIPTION.md §8): dx titles + the current prescription's public id. */
final class VisitsTimelineSource extends ModelTimelineSource
{
    public function kind(): string
    {
        return 'visit';
    }

    /** @return array<int, TimelineEntry> */
    public function entries(Patient $patient, ?TimelineCursor $cursor, int $limit): array
    {
        if (! Schema::connection('pgsql')->hasTable('visits')) {
            return [];
        }

        return $this->applyCursor(Visit::query()->where('patient_id', $patient->id)->with(['doctor:id,name', 'serial:id,display_code', 'currentPrescription:id,public_id,status,version']), 'started_at', $cursor)
            ->limit($limit)->get()
            ->map(function (Visit $v): TimelineEntry {
                $dx = array_values(array_filter(array_map(fn ($d) => (string) ($d['title'] ?? $d['icd10_code'] ?? ''), $v->diagnoses)));
                $rx = $v->currentPrescription;

                return new TimelineEntry(
                    kind: $this->kind(), id: $v->id, occurredAt: $v->started_at,
                    title: $dx !== [] ? implode(', ', $dx) : ucfirst($v->type->value).' visit',
                    subtitle: trim($v->doctor->name.($v->serial !== null ? ' · '.$v->serial->display_code : '')) ?: null,
                    ref: $v->public_id,
                    meta: ['type' => $v->type->value, 'status' => $v->status->value, 'diagnoses' => array_values($v->diagnoses), 'follow_up_on' => $v->follow_up_on?->toDateString(),
                        'prescription_id' => $rx?->public_id, 'prescription_status' => $rx?->status->value, 'prescription_version' => $rx?->version],
                );
            })->all();
    }
}
