<?php

declare(strict_types=1);

namespace App\Domain\Patients\Data;

use App\Domain\Clinic\Enums\Gender;
use App\Domain\Clinic\Enums\Locale;
use App\Domain\Patients\Enums\BloodGroup;
use App\Domain\Patients\Enums\PatientRelation as RelationType;
use App\Domain\Patients\Enums\PatientSource;
use App\Domain\Patients\Services\MobileNumber;
use App\Support\Clock;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Create / update payload. `ageYears` is turned into an estimated dob (1 January of the birth year) when no dob
 * is given — SCHEMA `dob_is_estimated`. `primaryPublicId` + `relation` link a new patient into an existing household.
 */
final readonly class PatientData
{
    /** E.164 — normalised from any accepted form by the constructor (SCHEMA §5.4). */
    public string $mobile;

    /** @param  array<int, string>  $tags */
    public function __construct(
        public string $name,
        string $mobile,
        public ?Gender $gender = null,
        public ?CarbonImmutable $dob = null,
        public ?int $ageYears = null,
        public ?BloodGroup $bloodGroup = null,
        public ?string $email = null,
        public ?string $address = null,
        public ?string $district = null,
        public ?string $nationalId = null,
        public ?string $guardianName = null,
        public Locale $preferredLanguage = Locale::Bn,
        public ?string $notes = null,
        public array $tags = [],
        public ?int $registeredBranchId = null,
        public ?int $registeredByUserId = null,
        public PatientSource $source = PatientSource::Counter,
        public bool $isActive = true,
        public ?string $primaryPublicId = null,
        public RelationType $relation = RelationType::Other,
    ) {
        $this->mobile = MobileNumber::normalize($mobile);
    }

    public static function fromRequest(FormRequest $request): self
    {
        return self::fromArray($request->validated(), $request->user('web')?->getKey());
    }

    /** @param  array<string, mixed>  $v  validated input (StorePatientRequest::patientRules keys) */
    public static function fromArray(array $v, ?int $registeredByUserId = null): self
    {
        return new self(
            name: trim((string) $v['name']),
            mobile: (string) $v['mobile'],
            gender: isset($v['gender']) ? Gender::from((string) $v['gender']) : null,
            dob: isset($v['dob']) && $v['dob'] !== '' ? CarbonImmutable::parse((string) $v['dob'])->startOfDay() : null,
            ageYears: isset($v['age_years']) && $v['age_years'] !== '' ? (int) $v['age_years'] : null,
            bloodGroup: isset($v['blood_group']) && $v['blood_group'] !== '' ? BloodGroup::from((string) $v['blood_group']) : null,
            email: self::nullable($v['email'] ?? null),
            address: self::nullable($v['address'] ?? null),
            district: self::nullable($v['district'] ?? null),
            nationalId: self::nullable($v['national_id'] ?? null),
            guardianName: self::nullable($v['guardian_name'] ?? null),
            preferredLanguage: isset($v['preferred_language']) ? Locale::from((string) $v['preferred_language']) : Locale::Bn,
            notes: self::nullable($v['notes'] ?? null),
            tags: array_values(array_map('strval', $v['tags'] ?? [])),
            registeredBranchId: isset($v['registered_branch_id']) ? (int) $v['registered_branch_id'] : null,
            registeredByUserId: $registeredByUserId,
            source: isset($v['source']) ? PatientSource::from((string) $v['source']) : PatientSource::Counter,
            isActive: (bool) ($v['is_active'] ?? true),
            primaryPublicId: self::nullable($v['primary_public_id'] ?? null),
            relation: isset($v['relation']) ? RelationType::from((string) $v['relation']) : RelationType::Other,
        );
    }

    /**
     * The dob to store and whether it was derived from an age.
     *
     * @return array{0: CarbonImmutable|null, 1: bool}
     */
    public function resolvedDob(): array
    {
        if ($this->dob !== null) {
            return [$this->dob, false];
        }

        if ($this->ageYears !== null) {
            return [Clock::today()->subYears($this->ageYears)->startOfYear(), true];
        }

        return [null, false];
    }

    /**
     * Column map for create/update (identity + household columns are set by the actions).
     *
     * @return array<string, mixed>
     */
    public function toAttributes(): array
    {
        [$dob, $estimated] = $this->resolvedDob();

        return [
            'name' => $this->name,
            'mobile' => $this->mobile,
            'gender' => $this->gender,
            'dob' => $dob,
            'dob_is_estimated' => $estimated,
            'blood_group' => $this->bloodGroup,
            'email' => $this->email,
            'address' => $this->address,
            'district' => $this->district,
            'national_id' => $this->nationalId,
            'guardian_name' => $this->guardianName,
            'preferred_language' => $this->preferredLanguage,
            'notes' => $this->notes,
            'tags' => $this->tags,
            'registered_branch_id' => $this->registeredBranchId,
            'source' => $this->source,
            'is_active' => $this->isActive,
        ];
    }

    private static function nullable(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
