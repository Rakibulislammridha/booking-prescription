<?php

declare(strict_types=1);

namespace App\Domain\Prescription\Actions;

use App\Domain\Shared\Actor;
use App\Models\Tenant\ExternalDiagnosticCentre;

final class SaveExternalDiagnosticCentre
{
    /** @param  array<string, mixed>  $data  validated request body */
    public function handle(array $data, Actor $actor, ?ExternalDiagnosticCentre $existing = null): ExternalDiagnosticCentre
    {
        $centre = $existing ?? new ExternalDiagnosticCentre;
        $centre->fill(array_intersect_key($data, array_flip(['name', 'address', 'phone', 'contact_person', 'notes', 'is_active'])));
        $centre->save();

        return $centre;
    }
}
