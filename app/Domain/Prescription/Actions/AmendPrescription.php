<?php

declare(strict_types=1);

namespace App\Domain\Prescription\Actions;

use App\Domain\Prescription\Enums\PrescriptionStatus;
use App\Domain\Prescription\Exceptions\NotLatestVersion;
use App\Domain\Prescription\Exceptions\VisitHasDraft;
use App\Domain\Prescription\Services\HandwritingStorage;
use App\Domain\Prescription\Services\PrescriptionAuditor;
use App\Domain\Shared\Actor;
use App\Models\Tenant\Prescription;
use Illuminate\Support\Facades\DB;

/**
 * PRESCRIPTION.md §6.3: allowed only on the latest issued version of a chain, and only while the visit has no draft.
 * Creates version+1 as a draft (supersedes / root / amend_reason), copies children with fresh ids and the
 * handwriting/drawing files (copied, not moved). The old row is untouched until the new one issues.
 */
final class AmendPrescription
{
    public function __construct(private readonly PrescriptionAuditor $auditor, private readonly HandwritingStorage $files) {}

    public function handle(Prescription $issued, string $reason, Actor $actor): Prescription
    {
        return DB::transaction(function () use ($issued, $reason): Prescription {
            /** @var Prescription $old */
            $old = Prescription::query()->whereKey($issued->id)->lockForUpdate()->firstOrFail();

            if ($old->status !== PrescriptionStatus::Issued) {
                throw new NotLatestVersion($old->id, "status is {$old->status->value}; only the latest issued version can be amended");
            }

            if ($old->supersededBy()->exists()) {
                throw new NotLatestVersion($old->id, 'this version has already been amended');
            }

            if (Prescription::query()->where('visit_id', $old->visit_id)->draft()->exists()) {
                throw new VisitHasDraft($old->visit_id);
            }

            $new = new Prescription;
            $new->fill([
                'visit_id' => $old->visit_id, 'patient_id' => $old->patient_id, 'doctor_id' => $old->doctor_id, 'branch_id' => $old->branch_id,
                'version' => $old->version + 1, 'root_prescription_id' => $old->root_prescription_id ?? $old->id, 'supersedes_prescription_id' => $old->id,
                'status' => PrescriptionStatus::Draft, 'language' => $old->language, 'amend_reason' => $reason, 'drawing_json' => $old->drawing_json,
            ]);
            $new->save();

            foreach (['items', 'investigations', 'advice', 'referrals'] as $relation) {
                foreach ($old->{$relation}()->get() as $child) {
                    $copy = $child->replicate();
                    $copy->prescription_id = $new->id;
                    $copy->save();
                }
            }

            $paths = $this->files->copyForVersion($old, $new);

            if ($paths['handwriting_image_path'] !== null || $paths['drawing_image_path'] !== null) {
                $new->forceFill(array_filter($paths, fn ($v) => $v !== null))->save();
            }

            $old->visit->forceFill(['current_prescription_id' => $new->id])->save();
            $this->auditor->amendStarted($old, $new, $reason);

            return $new;
        });
    }
}
