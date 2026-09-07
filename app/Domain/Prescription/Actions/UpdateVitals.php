<?php

declare(strict_types=1);

namespace App\Domain\Prescription\Actions;

use App\Domain\Prescription\Data\VitalsData;
use App\Domain\Prescription\Services\PrescriptionAuditor;
use App\Domain\Shared\Actor;
use App\Models\Tenant\Vital;

/**
 * Doctor edits / reviews the compounder's row (§4.2): edits set edited_by_doctor and reviewed_by_doctor_at; the
 * "Reviewed" tick alone sets reviewed_by_doctor_at. Audited `update {event: vitals_updated|vitals_reviewed}`.
 */
final class UpdateVitals
{
    public function __construct(private readonly PrescriptionAuditor $auditor) {}

    public function handle(Vital $vital, VitalsData $data, Actor $actor, bool $byDoctor = true): Vital
    {
        $vital->fill($data->toAttributes());
        $edited = $vital->isDirty();

        if ($byDoctor && $edited) {
            $vital->edited_by_doctor = true;
        }

        if ($byDoctor && ($edited || $data->reviewed === true) && $vital->reviewed_by_doctor_at === null) {
            $vital->reviewed_by_doctor_at = now();
        }

        if (! $vital->isDirty()) {
            return $vital;
        }

        $dirty = $vital->getDirty();
        $before = array_intersect_key($vital->getOriginal(), $dirty);
        $vital->save();
        $this->auditor->vitalsUpdated($vital, $before, $dirty, ! $edited);

        return $vital;
    }
}
