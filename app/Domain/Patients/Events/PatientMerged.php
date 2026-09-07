<?php

declare(strict_types=1);

namespace App\Domain\Patients\Events;

use App\Models\Tenant\Patient;
use Illuminate\Foundation\Events\Dispatchable;

/** The loser is soft-deleted; every FK that pointed at it now points at the winner. */
final class PatientMerged
{
    use Dispatchable;

    public function __construct(public readonly Patient $winner, public readonly Patient $loser) {}
}
