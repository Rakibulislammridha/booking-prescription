<?php

declare(strict_types=1);

namespace App\Domain\Billing\Services;

use App\Domain\Billing\Enums\CashShiftStatus;
use App\Models\Tenant\CashShift;

/** The open cash drawer of a user, if any. One per user — the partial unique index says so. */
final class CurrentShift
{
    public function forUser(?int $userId): ?CashShift
    {
        if ($userId === null) {
            return null;
        }

        return CashShift::query()->where('user_id', $userId)->where('status', CashShiftStatus::Open->value)->first();
    }

    public function idForUser(?int $userId): ?int
    {
        return $this->forUser($userId)?->id;
    }
}
