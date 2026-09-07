<?php

declare(strict_types=1);

namespace App\Domain\Prescription\Actions;

use App\Domain\Shared\Actor;
use App\Models\Tenant\ExternalDiagnosticCentre;

final class DeleteExternalDiagnosticCentre
{
    public function handle(ExternalDiagnosticCentre $centre, Actor $actor): void
    {
        $centre->forceFill(['is_active' => false])->save();
    }
}
