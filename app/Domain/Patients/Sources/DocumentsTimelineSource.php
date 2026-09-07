<?php

declare(strict_types=1);

namespace App\Domain\Patients\Sources;

use App\Domain\Patients\Data\TimelineCursor;
use App\Domain\Patients\Data\TimelineEntry;
use App\Models\Tenant\Patient;
use App\Models\Tenant\PatientDocument;

final class DocumentsTimelineSource extends ModelTimelineSource
{
    public function kind(): string
    {
        return 'document';
    }

    /** @return array<int, TimelineEntry> */
    public function entries(Patient $patient, ?TimelineCursor $cursor, int $limit): array
    {
        return $this->applyCursor(PatientDocument::query()->where('patient_id', $patient->id), 'created_at', $cursor)
            ->limit($limit)
            ->get()
            ->map(fn (PatientDocument $d) => new TimelineEntry(
                kind: $this->kind(),
                id: $d->id,
                occurredAt: $d->created_at,
                title: $d->title,
                subtitle: $d->type->value,
                ref: (string) $d->id,
                meta: ['type' => $d->type->value, 'document_date' => $d->document_date?->toDateString(), 'mime_type' => $d->mime_type, 'size_bytes' => $d->size_bytes, 'ocr_status' => $d->ocr_status->value],
            ))
            ->all();
    }
}
