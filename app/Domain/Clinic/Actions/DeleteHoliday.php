<?php

declare(strict_types=1);

namespace App\Domain\Clinic\Actions;

use App\Domain\Shared\Actor;
use App\Models\Tenant\Holiday;

final class DeleteHoliday
{
    public function handle(Holiday $holiday, Actor $actor): void
    {
        $holiday->delete();
    }
}
