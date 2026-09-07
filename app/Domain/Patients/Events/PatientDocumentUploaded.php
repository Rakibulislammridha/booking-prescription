<?php

declare(strict_types=1);

namespace App\Domain\Patients\Events;

use App\Models\Tenant\PatientDocument;
use Illuminate\Foundation\Events\Dispatchable;

final class PatientDocumentUploaded
{
    use Dispatchable;

    public function __construct(public readonly PatientDocument $document) {}
}
