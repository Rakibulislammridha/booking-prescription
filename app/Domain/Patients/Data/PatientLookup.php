<?php

declare(strict_types=1);

namespace App\Domain\Patients\Data;

use App\Domain\Clinic\Enums\Gender;
use App\Domain\Patients\Enums\PatientRelation as RelationType;
use App\Domain\Patients\Enums\PatientSource;
use Carbon\CarbonImmutable;

/**
 * Input of FindOrCreatePatientByMobile — the booking-flow primitive (BRIEF §5.C). Only `mobile` is required:
 * with no name the household on that number is looked up; with a name the person is matched by
 * (mobile, name, dob) and created as a dependent of the mobile owner when absent (SCHEMA §5.4).
 */
final readonly class PatientLookup
{
    public function __construct(
        public string $mobile,
        public ?string $name = null,
        public ?CarbonImmutable $dob = null,
        public ?int $ageYears = null,
        public ?Gender $gender = null,
        public PatientSource $source = PatientSource::Counter,
        public ?int $registeredBranchId = null,
        public ?int $registeredByUserId = null,
        public RelationType $relation = RelationType::Other,
        public bool $createIfMissing = true,
    ) {}
}
