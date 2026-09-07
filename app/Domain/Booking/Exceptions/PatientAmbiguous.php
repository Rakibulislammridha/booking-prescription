<?php

declare(strict_types=1);

namespace App\Domain\Booking\Exceptions;

use App\Domain\Shared\Exceptions\DomainException;
use App\Models\Tenant\Patient;
use Illuminate\Support\Collection;

/** Several people share the mobile and no name/dob told them apart — the caller must show the household and ask. */
final class PatientAmbiguous extends DomainException
{
    /** @param  Collection<int, Patient>  $household */
    public function __construct(public readonly Collection $household)
    {
        parent::__construct(__('booking.errors.patient_ambiguous'));
    }

    public function code(): string
    {
        return 'booking.patient_ambiguous';
    }

    public function status(): int
    {
        return 409;
    }
}
