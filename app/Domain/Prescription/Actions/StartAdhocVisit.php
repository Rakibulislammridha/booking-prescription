<?php

declare(strict_types=1);

namespace App\Domain\Prescription\Actions;

use App\Domain\Prescription\Enums\VisitStatus;
use App\Domain\Prescription\Enums\VisitType;
use App\Domain\Prescription\Services\PrescriptionAuditor;
use App\Domain\Shared\Actor;
use App\Models\Tenant\Branch;
use App\Models\Tenant\Doctor;
use App\Models\Tenant\Patient;
use App\Models\Tenant\Visit;
use Illuminate\Support\Facades\DB;

/** A visit without a serial (SCHEMA §3.4: "Null for an ad-hoc visit created from a bare serial" — or none at all). */
final class StartAdhocVisit
{
    public function __construct(private readonly PrescriptionAuditor $auditor) {}

    public function handle(Patient $patient, Doctor $doctor, Branch $branch, Actor $actor, VisitType $type = VisitType::Opd): Visit
    {
        return DB::transaction(function () use ($patient, $doctor, $branch, $type): Visit {
            $visit = new Visit;
            $visit->fill(['patient_id' => $patient->id, 'doctor_id' => $doctor->id, 'branch_id' => $branch->id, 'type' => $type, 'status' => VisitStatus::Open, 'started_at' => now()]);
            $visit->save();

            $patient->forceFill(['last_visit_at' => now(), 'visit_count' => $patient->visit_count + 1])->save();
            $this->auditor->visitStarted($visit, 'adhoc');

            return $visit;
        });
    }
}
