<?php

declare(strict_types=1);

namespace App\Domain\Prescription\Actions;

use App\Domain\Prescription\Enums\PrescriptionStatus;
use App\Domain\Prescription\Enums\VisitStatus;
use App\Domain\Prescription\Exceptions\VisitNotOpen;
use App\Domain\Prescription\Services\PrescriptionAuditor;
use App\Domain\Shared\Actor;
use App\Models\Tenant\Doctor;
use App\Models\Tenant\Prescription;
use App\Models\Tenant\Visit;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * The visit's one draft (partial unique index): returned when it exists, created otherwise — inside the writer's
 * open request so the first autosave already has a target (PRESCRIPTION.md §1.1). Language seeds from the pad.
 */
final class CreateDraftPrescription
{
    public function __construct(private readonly PrescriptionAuditor $auditor) {}

    public function handle(Visit $visit, Doctor $doctor, Actor $actor): Prescription
    {
        $existing = Prescription::query()->where('visit_id', $visit->id)->draft()->first();

        if ($existing !== null) {
            return $existing;
        }

        if ($visit->status !== VisitStatus::Open) {
            throw new VisitNotOpen($visit->id, $visit->status->value);
        }

        try {
            return DB::transaction(function () use ($visit, $doctor): Prescription {
                $rx = new Prescription;
                $rx->fill([
                    'visit_id' => $visit->id, 'patient_id' => $visit->patient_id, 'doctor_id' => $doctor->id, 'branch_id' => $visit->branch_id,
                    'version' => 1, 'status' => PrescriptionStatus::Draft,
                    'language' => (string) ($doctor->padSetting->default_language ?? 'both'),
                ]);
                $rx->save();
                $rx->forceFill(['root_prescription_id' => $rx->id])->save();

                $visit->forceFill(['current_prescription_id' => $rx->id])->save();
                $this->auditor->draftCreated($rx);

                return $rx;
            });
        } catch (QueryException $e) {
            $again = Prescription::query()->where('visit_id', $visit->id)->draft()->first();

            if ($again !== null) {
                return $again;
            }

            throw $e;
        }
    }
}
