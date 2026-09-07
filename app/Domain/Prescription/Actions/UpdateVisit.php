<?php

declare(strict_types=1);

namespace App\Domain\Prescription\Actions;

use App\Domain\Prescription\Data\VisitData;
use App\Domain\Prescription\Enums\VisitStatus;
use App\Domain\Prescription\Exceptions\VisitNotOpen;
use App\Domain\Prescription\Services\PrescriptionAuditor;
use App\Domain\Shared\Actor;
use App\Models\Tenant\Visit;

/** Complaints / findings / diagnoses / follow-up / private notes on an open visit (audited `update`). */
final class UpdateVisit
{
    public function __construct(private readonly PrescriptionAuditor $auditor) {}

    public function handle(Visit $visit, VisitData $data, Actor $actor, string $event = 'visit_updated'): Visit
    {
        if ($visit->status !== VisitStatus::Open) {
            throw new VisitNotOpen($visit->id, $visit->status->value);
        }

        $visit->fill($data->toAttributes());

        if (! $visit->isDirty()) {
            return $visit;
        }

        $dirty = $visit->getDirty();
        $before = array_intersect_key($visit->getOriginal(), $dirty);
        $visit->save();
        $this->auditor->visitUpdated($visit, $this->plain($before), $this->plain($dirty), $event);

        return $visit;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function plain(array $attributes): array
    {
        return array_map(fn ($v) => is_string($v) && json_validate($v) ? json_decode($v, true) : ($v instanceof \BackedEnum ? $v->value : $v), $attributes);
    }
}
