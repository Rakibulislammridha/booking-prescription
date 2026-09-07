<?php

declare(strict_types=1);

namespace App\Domain\Prescription\Sources;

use App\Domain\Patients\Data\TimelineCursor;
use App\Domain\Patients\Data\TimelineEntry;
use App\Domain\Patients\Sources\ModelTimelineSource;
use App\Models\Tenant\Patient;
use App\Models\Tenant\Prescription;
use Illuminate\Support\Facades\Schema;

/** kind `prescription`: issued / amended / voided versions by issued_at (drafts never appear on the timeline). */
final class PrescriptionsTimelineSource extends ModelTimelineSource
{
    public function kind(): string
    {
        return 'prescription';
    }

    /** @return array<int, TimelineEntry> */
    public function entries(Patient $patient, ?TimelineCursor $cursor, int $limit): array
    {
        if (! Schema::connection('pgsql')->hasTable('prescriptions')) {
            return [];
        }

        return $this->applyCursor(Prescription::query()->where('patient_id', $patient->id)->whereNotNull('issued_at')->withCount('items')->with('doctor:id,name'), 'issued_at', $cursor)
            ->limit($limit)->get()
            ->map(fn (Prescription $rx) => new TimelineEntry(
                kind: $this->kind(), id: $rx->id, occurredAt: $rx->issued_at,
                title: "Prescription v{$rx->version} · ".$rx->items_count.' item'.($rx->items_count === 1 ? '' : 's'),
                subtitle: trim($rx->doctor->name.' · '.$rx->status->value),
                ref: $rx->public_id,
                meta: ['status' => $rx->status->value, 'version' => $rx->version, 'verification_code' => $rx->verification_code, 'item_count' => (int) $rx->items_count, 'visit_id' => $rx->visit_id, 'pdf_ready' => $rx->pdf_path !== null],
            ))->all();
    }
}
