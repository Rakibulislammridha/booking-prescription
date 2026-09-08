<?php

declare(strict_types=1);

namespace App\Domain\Telemedicine\Exceptions;

use App\Domain\Shared\Exceptions\DomainException;

/** Recording is off for this clinic, or the patient never consented to it (patient_consents `telemedicine`). */
final class RecordingNotAllowed extends DomainException
{
    public function __construct()
    {
        parent::__construct(__('telemedicine.errors.recording_not_allowed'));
    }

    public function code(): string
    {
        return 'telemedicine.recording_not_allowed';
    }
}
