<?php

declare(strict_types=1);

namespace App\Domain\Patients\Data;

use App\Models\Tenant\Patient;
use Illuminate\Support\Collection;

/**
 * Result of FindOrCreatePatientByMobile. `patient` is the matched or newly created person (null only when the
 * lookup had no name, the household is empty and createIfMissing was false); `household` lists everyone on the
 * mobile (owner first) so the caller can show "which family member?"; `ambiguous` is true when no name was given
 * and more than one person shares the number — the returned patient is then the mobile owner.
 */
final readonly class PatientMatch
{
    /** @param  Collection<int, Patient>  $household */
    public function __construct(
        public ?Patient $patient,
        public bool $created,
        public Collection $household,
        public bool $ambiguous = false,
    ) {}
}
