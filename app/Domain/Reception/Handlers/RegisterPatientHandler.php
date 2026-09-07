<?php

declare(strict_types=1);

namespace App\Domain\Reception\Handlers;

use App\Domain\Clinic\Enums\Gender;
use App\Domain\Patients\Actions\CreatePatient;
use App\Domain\Patients\Actions\FindOrCreatePatientByMobile;
use App\Domain\Patients\Data\PatientData;
use App\Domain\Patients\Data\PatientLookup;
use App\Domain\Patients\Enums\PatientRelation;
use App\Domain\Patients\Enums\PatientSource;
use App\Domain\Patients\Exceptions\DuplicatePatient;
use App\Domain\Patients\Services\MobileNumber;
use App\Domain\Reception\Enums\ConflictReason;
use App\Domain\Reception\Enums\ConflictResolution;
use App\Domain\Reception\Services\SerialPresenter;
use App\Domain\Reception\Sync\ReplayContext;
use App\Domain\Reception\Sync\ReplayHandler;
use App\Domain\Reception\Sync\ReplayOutcome;
use App\Domain\Reception\Sync\Resolution;
use App\Models\Tenant\OfflineEvent;
use App\Models\Tenant\Patient;
use Carbon\CarbonImmutable;

/**
 * OFFLINE §7.2 / §8.1: a stub whose mobile is new is created; a mobile that already has ≥ 1 patient is a
 * `duplicate_patient` conflict (household phones are common — even an exact name match is never auto-linked).
 * Resolutions: link_patient {patient} · family_member {holder_patient?} (a new dependent of the mobile owner).
 */
final class RegisterPatientHandler implements ReplayHandler
{
    public function __construct(
        private readonly FindOrCreatePatientByMobile $findOrCreate,
        private readonly CreatePatient $create,
    ) {}

    public function handle(OfflineEvent $event, ReplayContext $ctx, ?Resolution $resolution = null): ReplayOutcome
    {
        $p = $event->payload;
        $localId = trim((string) ($p['localId'] ?? ''));
        $name = trim((string) ($p['name'] ?? ''));
        $mobile = MobileNumber::tryNormalize((string) ($p['mobile'] ?? ''));

        if ($localId === '' || $name === '' || $mobile === null) {
            return ReplayOutcome::rejected('payload_invalid', __('reception.sync.payload_invalid'));
        }

        $lookup = new PatientLookup(
            mobile: $mobile,
            name: $name,
            dob: isset($p['dob']) && is_string($p['dob']) && $p['dob'] !== '' ? CarbonImmutable::parse($p['dob'])->startOfDay() : null,
            ageYears: isset($p['ageYears']) && is_numeric($p['ageYears']) ? (int) $p['ageYears'] : null,
            gender: self::gender($p['sex'] ?? null),
            source: PatientSource::Counter,
            registeredBranchId: $ctx->device->branch_id,
            registeredByUserId: $ctx->actor->id,
            relation: PatientRelation::tryFrom((string) ($p['relationToHolder'] ?? '')) ?? PatientRelation::Other,
        );

        if ($resolution === null) {
            $household = Patient::query()->household($mobile)->get();

            if ($household->isNotEmpty()) {
                return ReplayOutcome::conflict(ConflictReason::DuplicatePatient, [
                    'candidates' => $household->map(fn (Patient $c) => self::candidate($c))->values()->all(),
                    'stub' => ['localId' => $localId, 'mobile' => $mobile, 'name' => $name, 'sex' => $p['sex'] ?? null, 'ageYears' => $p['ageYears'] ?? null, 'dob' => $p['dob'] ?? null],
                ]);
            }

            $match = ($this->findOrCreate)($lookup);
            $patient = $match->patient;

            if ($patient === null) {
                return ReplayOutcome::rejected('payload_invalid', __('reception.sync.payload_invalid'));
            }

            return $this->accept($ctx, $localId, $patient, $match->created);
        }

        if ($resolution->resolution === ConflictResolution::LinkPatient) {
            $patient = Patient::query()->where('public_id', (string) $resolution->string('patient'))->first();

            if ($patient === null) {
                return ReplayOutcome::rejected('payload_invalid', __('reception.sync.unknown_patient'));
            }

            return $this->accept($ctx, $localId, $patient, false, linked: true);
        }

        // family_member: an explicit "new person on this number" — created as a dependent of the (named) holder.
        $holder = $resolution->string('holder_patient');
        $data = new PatientData(
            name: $name, mobile: $mobile, gender: $lookup->gender, dob: $lookup->dob, ageYears: $lookup->ageYears,
            registeredBranchId: $ctx->device->branch_id, registeredByUserId: $ctx->actor->id, source: PatientSource::Counter,
            primaryPublicId: $holder, relation: $lookup->relation,
        );

        try {
            $patient = $this->create->handle($data, $ctx->actorDto);
            $created = true;
        } catch (DuplicatePatient) {
            // exactly this person (mobile + name + dob) already exists: link instead of creating a twin
            $patient = ($this->findOrCreate)($lookup->createIfMissing ? new PatientLookup(mobile: $mobile, name: $name, dob: $lookup->dob, ageYears: $lookup->ageYears, createIfMissing: false) : $lookup)->patient;
            $created = false;

            if ($patient === null) {
                return ReplayOutcome::rejected('payload_invalid', __('reception.sync.payload_invalid'));
            }
        }

        return $this->accept($ctx, $localId, $patient, $created);
    }

    private function accept(ReplayContext $ctx, string $localId, Patient $patient, bool $created, bool $linked = false): ReplayOutcome
    {
        $ctx->mapPatient($localId, $patient);

        return ReplayOutcome::accepted(['patient' => SerialPresenter::patient($patient) + ['created' => $created, 'linked' => $linked]]);
    }

    /** @return array<string, mixed> */
    private static function candidate(Patient $patient): array
    {
        return SerialPresenter::patient($patient) + [
            'age' => $patient->age_years,
            'relation' => $patient->primaryRelation?->relation?->value,
            'last_visit' => $patient->last_visit_at?->toDateString(),
        ];
    }

    private static function gender(mixed $sex): ?Gender
    {
        return match ($sex) {
            'm', 'male' => Gender::Male,
            'f', 'female' => Gender::Female,
            'o', 'other' => Gender::Other,
            default => null,
        };
    }
}
