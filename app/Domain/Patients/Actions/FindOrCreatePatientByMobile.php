<?php

declare(strict_types=1);

namespace App\Domain\Patients\Actions;

use App\Domain\Patients\Data\PatientLookup;
use App\Domain\Patients\Data\PatientMatch;
use App\Domain\Patients\Events\PatientCreated;
use App\Domain\Patients\Exceptions\PatientNameRequired;
use App\Domain\Patients\Services\MobileNumber;
use App\Models\Tenant\Patient;
use App\Models\Tenant\PatientRelation;
use App\Support\Clock;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * The booking-flow primitive (BRIEF §5.C: "mobile number → patient auto-matched or created"). Booking, kiosk,
 * reception and the offline replay handler all call this; it is idempotent for the same (mobile, name, dob).
 *
 *   $match = app(FindOrCreatePatientByMobile::class)(new PatientLookup(mobile: '017…', name: 'Rahim', dob: …));
 *   $match->patient   // matched or created person
 *   $match->created   // true when a row was inserted
 *   $match->household // everyone on that mobile, owner first (show it when $match->ambiguous)
 *
 * Rules (SCHEMA §5.4):
 *  1. mobile is normalised to E.164; the household is every live patient on it.
 *  2. No name given: one person → that person; several → the mobile owner with ambiguous = true (UI shows the
 *     list); nobody → PatientNameRequired (a person cannot be created without a name).
 *  3. Name given: exact match on name_normalized within the household; several namesakes are disambiguated by
 *     dob (an exact dob wins; a single namesake matches regardless of dob). No match → create: as the mobile
 *     owner when the household is empty, otherwise as a dependent linked to the owner via patient_relations.
 *  4. Concurrency: the identity unique index is the guard; a lost race is resolved by one re-lookup.
 */
final class FindOrCreatePatientByMobile
{
    public function __invoke(PatientLookup $lookup): PatientMatch
    {
        $mobile = MobileNumber::normalize($lookup->mobile);
        $household = $this->household($mobile);

        if ($lookup->name === null || trim($lookup->name) === '') {
            return $this->withoutName($household, $lookup);
        }

        $matched = $this->match($household, $lookup);

        if ($matched !== null) {
            return new PatientMatch($matched, false, $household);
        }

        if (! $lookup->createIfMissing || $this->isAmbiguous($household, $lookup)) {
            return new PatientMatch(null, false, $household, ambiguous: $this->isAmbiguous($household, $lookup));
        }

        try {
            $created = DB::transaction(fn () => $this->create($mobile, $household, $lookup));
        } catch (UniqueConstraintViolationException) {
            $household = $this->household($mobile);

            return new PatientMatch($this->match($household, $lookup), false, $household);
        }

        return new PatientMatch($created, true, $this->household($mobile));
    }

    /** @param  Collection<int, Patient>  $household */
    private function withoutName(Collection $household, PatientLookup $lookup): PatientMatch
    {
        if ($household->isEmpty()) {
            if (! $lookup->createIfMissing) {
                return new PatientMatch(null, false, $household);
            }

            throw new PatientNameRequired;
        }

        $owner = $household->firstWhere('is_mobile_owner', true) ?? $household->first();

        return new PatientMatch($owner, false, $household, ambiguous: $household->count() > 1);
    }

    /** @param  Collection<int, Patient>  $household */
    private function match(Collection $household, PatientLookup $lookup): ?Patient
    {
        $name = self::normalizeName((string) $lookup->name);
        $namesakes = $household->filter(fn (Patient $p) => $p->name_normalized === $name)->values();

        if ($namesakes->isEmpty()) {
            return null;
        }

        $dob = $this->dobOf($lookup)?->toDateString();

        if ($dob !== null) {
            $exact = $namesakes->first(fn (Patient $p) => $p->dob?->toDateString() === $dob);

            if ($exact !== null) {
                return $exact;
            }
        }

        if ($namesakes->count() === 1) {
            $only = $namesakes->first();

            // A stated dob that contradicts a known one is a different person (father and son, SCHEMA §5.4).
            return $dob !== null && $only->dob !== null && $only->dob->toDateString() !== $dob ? null : $only;
        }

        return $dob === null ? $namesakes->first(fn (Patient $p) => $p->dob === null) : null;
    }

    /**
     * Several namesakes on the mobile and no dob to tell them apart: never auto-create a third — the caller shows
     * the household and asks (SCHEMA §5.4 "create only on explicit new family member").
     *
     * @param  Collection<int, Patient>  $household
     */
    private function isAmbiguous(Collection $household, PatientLookup $lookup): bool
    {
        if ($this->dobOf($lookup) !== null) {
            return false;
        }

        $name = self::normalizeName((string) $lookup->name);

        return $household->filter(fn (Patient $p) => $p->name_normalized === $name)->count() > 1;
    }

    /** @param  Collection<int, Patient>  $household */
    private function create(string $mobile, Collection $household, PatientLookup $lookup): Patient
    {
        $owner = $household->firstWhere('is_mobile_owner', true);
        $dob = $this->dobOf($lookup);

        $patient = Patient::query()->create([
            'name' => trim((string) $lookup->name),
            'mobile' => $mobile,
            'is_mobile_owner' => $owner === null,
            'gender' => $lookup->gender,
            'dob' => $dob,
            'dob_is_estimated' => $lookup->dob === null && $lookup->ageYears !== null,
            'source' => $lookup->source,
            'registered_branch_id' => $lookup->registeredBranchId,
            'registered_by_user_id' => $lookup->registeredByUserId,
        ]);

        if ($owner !== null) {
            PatientRelation::query()->create([
                'primary_patient_id' => $owner->id,
                'dependent_patient_id' => $patient->id,
                'relation' => $lookup->relation,
            ]);
        }

        DB::afterCommit(fn () => event(new PatientCreated($patient)));

        return $patient;
    }

    /** @return Collection<int, Patient> */
    private function household(string $mobile): Collection
    {
        return Patient::query()->household($mobile)->get();
    }

    private function dobOf(PatientLookup $lookup): ?CarbonImmutable
    {
        if ($lookup->dob !== null) {
            return $lookup->dob->startOfDay();
        }

        return $lookup->ageYears === null ? null : Clock::today()->subYears($lookup->ageYears)->startOfYear();
    }

    /** Mirrors the generated column `lower(btrim(name))`. */
    public static function normalizeName(string $name): string
    {
        return mb_strtolower(trim($name));
    }
}
