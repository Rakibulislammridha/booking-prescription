<?php

declare(strict_types=1);

namespace App\Domain\Patients\Actions;

use App\Domain\Patients\Enums\ConditionStatus;
use App\Domain\Shared\Actor;
use App\Models\Tenant\PatientCondition;
use App\Support\Clock;

/** "Remove" = mark resolved today; the row (and its audit trail) stays. */
final class RemoveCondition
{
    public function handle(PatientCondition $condition, Actor $actor): PatientCondition
    {
        $condition->fill(['status' => ConditionStatus::Resolved, 'resolved_date' => $condition->resolved_date ?? Clock::today()])->save();

        return $condition;
    }
}
