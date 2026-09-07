<?php

declare(strict_types=1);

namespace App\Domain\Prescription\Actions;

use App\Domain\Patients\Actions\SaveMedication;
use App\Domain\Patients\Data\MedicationData;
use App\Domain\Patients\Enums\MedicationSource;
use App\Domain\Prescription\Data\IssueRequest;
use App\Domain\Prescription\Enums\PrescriptionStatus;
use App\Domain\Prescription\Enums\SafetyStage;
use App\Domain\Prescription\Events\FollowUpScheduled;
use App\Domain\Prescription\Events\PrescriptionIssued;
use App\Domain\Prescription\Exceptions\DraftConflict;
use App\Domain\Prescription\Exceptions\IssueBlocked;
use App\Domain\Prescription\Exceptions\PrescriptionNotDraft;
use App\Domain\Prescription\Services\CanonicalJson;
use App\Domain\Prescription\Services\DraftPersister;
use App\Domain\Prescription\Services\DraftSerializer;
use App\Domain\Prescription\Services\ItemResolver;
use App\Domain\Prescription\Services\PrescriptionAuditor;
use App\Domain\Prescription\Services\SafetyChecker;
use App\Domain\Prescription\Services\SnapshotBuilder;
use App\Domain\Prescription\Services\VerificationCode;
use App\Domain\Shared\Actor;
use App\Models\Tenant\DoctorPadSetting;
use App\Models\Tenant\Prescription;
use App\Models\Tenant\PrescriptionItem;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * PRESCRIPTION.md §6.1 — one transaction with SELECT … FOR UPDATE: re-parse every item (422 on errors), re-copy the
 * snapshot text from the live catalog NOW, run the pipeline at stage Issue (422 with the alert set when blocked),
 * freeze `snapshot` + sha256 + pad_snapshot, verification code, flip the superseded row to `amended`, write
 * patient_medications for continued items, audit `issue`, then (after commit) PrescriptionIssued / FollowUpScheduled.
 */
final class IssuePrescription
{
    public function __construct(
        private readonly ItemResolver $items,
        private readonly SafetyChecker $safety,
        private readonly DraftPersister $persister,
        private readonly SnapshotBuilder $snapshots,
        private readonly DraftSerializer $serializer,
        private readonly PrescriptionAuditor $auditor,
        private readonly SaveMedication $medications,
    ) {}

    public function handle(Prescription $rx, IssueRequest $req, Actor $actor): Prescription
    {
        return DB::transaction(function () use ($rx, $req, $actor): Prescription {
            /** @var Prescription $rx */
            $rx = Prescription::query()->whereKey($rx->id)->lockForUpdate()->firstOrFail();
            $rx->load(['items', 'investigations', 'advice', 'referrals', 'visit.patient.allergies', 'visit.patient.conditions', 'visit.patient.medications', 'visit.latestVitals', 'visit.serial', 'doctor.profile', 'doctor.padSetting']);

            if (! $rx->isDraft()) {
                throw new PrescriptionNotDraft($rx->id, $rx->status->value);
            }

            if ($req->expectedUpdatedAt !== null && $rx->updated_at !== null && ! CarbonImmutable::parse($req->expectedUpdatedAt)->equalTo($rx->updated_at->startOfSecond())) {
                throw new DraftConflict($this->serializer->draft($rx));
            }

            $language = $req->language ?? $rx->language->value;
            $prefs = (array) ($rx->doctor->profile->prefs ?? []);
            $contDays = (int) ($prefs['cont_days'] ?? config('prescription.cont_days', 30));

            // 2–3. re-parse + re-validate catalog ids + re-copy snapshot text from the live catalog (snapshot on write)
            $resolved = $this->items->fromRows($rx, $contDays, $language);
            $this->items->assertNoErrors($resolved);

            // 4. safety at stage Issue
            $report = $this->safety->check($rx, $resolved, SafetyStage::Issue);

            if ($report->blocked()) {
                throw new IssueBlocked($report);
            }

            $this->persister->items($rx, $resolved, $report, $actor, $language);
            $rx->unsetRelation('items')->load('items');

            // 5–6. freeze
            $pad = $rx->doctor->padSetting ?? new DoctorPadSetting(DoctorPadSetting::defaults());
            $code = VerificationCode::generate();
            $issuedAt = now();
            $rx->forceFill(['issued_at' => $issuedAt, 'language' => $language, 'root_prescription_id' => $rx->root_prescription_id ?? $rx->id]);
            $overrides = $rx->items->flatMap(fn (PrescriptionItem $i) => $i->safety_overrides)->unique('fingerprint')->values()->all();
            $snapshot = $this->snapshots->build($rx, $pad, $this->safety->catalogVersion(), ['alerts' => $report->alertsArray(), 'overrides' => $overrides], $code);

            $rx->forceFill([
                'status' => PrescriptionStatus::Issued,
                'issued_by_user_id' => $actor->userId,
                'verification_code' => $code,
                'snapshot' => $snapshot,
                'snapshot_sha256' => CanonicalJson::sha256($snapshot),
                'pad_snapshot' => $this->snapshots->padArray($pad),
            ])->save();

            $visit = $rx->visit;
            $visit->forceFill(['current_prescription_id' => $rx->id])->save();
            $this->writeMedications($rx, $actor, $req->addToMedicationList);

            // 7. amendment: the superseded row flips to `amended` (its only permitted transition)
            if ($rx->supersedes_prescription_id !== null) {
                /** @var Prescription $old */
                $old = Prescription::query()->whereKey($rx->supersedes_prescription_id)->lockForUpdate()->firstOrFail();
                $old->forceFill(['status' => PrescriptionStatus::Amended])->save();
                $this->auditor->superseded($old, $rx);
            }

            // 8. audit
            $this->auditor->issued($rx, SafetyChecker::summary($report));

            // 9. after commit (ShouldDispatchAfterCommit)
            PrescriptionIssued::dispatch($rx->tenant_id, $rx->id, $rx->visit_id, $rx->patient_id, $rx->doctor_id, $visit->serial_id, $rx->public_id, $rx->version, $code);

            if ($visit->follow_up_on !== null) {
                FollowUpScheduled::dispatch($rx->tenant_id, $rx->id, $rx->visit_id, $rx->patient_id, $rx->doctor_id, $rx->branch_id, $visit->follow_up_on->toDateString(), $visit->follow_up_note, true);
            }

            return $rx;
        });
    }

    /** patient_medications (source = prescription) for `is_continued` items — or every item when the doctor ticked it. */
    private function writeMedications(Prescription $rx, Actor $actor, bool $all): void
    {
        foreach ($rx->items as $item) {
            if (! $all && ! $item->is_continued) {
                continue;
            }

            if ($item->generic_id === null) {
                continue;
            }

            $this->medications->handle($rx->patient, new MedicationData(
                genericName: $item->generic_name, brandName: $item->brand_name, genericId: $item->generic_id, brandId: $item->brand_id,
                customBrandId: $item->custom_brand_id, doseText: trim(($item->dose_schedule ?? '').' '.($item->duration_text ?? '')) ?: null,
                source: MedicationSource::Prescription, prescriptionItemId: $item->id, startedOn: now()->toImmutable()->startOfDay(),
            ), $actor);
        }
    }
}
