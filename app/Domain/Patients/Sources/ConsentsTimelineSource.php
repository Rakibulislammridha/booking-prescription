<?php

declare(strict_types=1);

namespace App\Domain\Patients\Sources;

use App\Domain\Patients\Data\TimelineCursor;
use App\Domain\Patients\Data\TimelineEntry;
use App\Models\Tenant\Patient;
use App\Models\Tenant\PatientConsent;

final class ConsentsTimelineSource extends ModelTimelineSource
{
    public function kind(): string
    {
        return 'consent';
    }

    /** @return array<int, TimelineEntry> */
    public function entries(Patient $patient, ?TimelineCursor $cursor, int $limit): array
    {
        return $this->applyCursor(PatientConsent::query()->where('patient_id', $patient->id), 'occurred_at', $cursor)
            ->limit($limit)
            ->get()
            ->map(fn (PatientConsent $c) => new TimelineEntry(
                kind: $this->kind(),
                id: $c->id,
                occurredAt: $c->occurred_at,
                title: $c->type->value,
                subtitle: $c->status->value,
                ref: (string) $c->id,
                meta: ['type' => $c->type->value, 'status' => $c->status->value, 'channel' => $c->channel->value, 'policy_version' => $c->policy_version],
            ))
            ->all();
    }
}
