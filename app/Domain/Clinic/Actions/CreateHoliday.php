<?php

declare(strict_types=1);

namespace App\Domain\Clinic\Actions;

use App\Domain\Clinic\Data\HolidayData;
use App\Domain\Clinic\Exceptions\HolidayAlreadyExists;
use App\Domain\Shared\Actor;
use App\Models\Tenant\Holiday;

final class CreateHoliday
{
    public function handle(HolidayData $data, Actor $actor): Holiday
    {
        $exists = Holiday::query()
            ->whereDate('holiday_date', $data->holidayDate->toDateString())
            ->where(fn ($q) => $data->branchId === null ? $q->whereNull('branch_id') : $q->where('branch_id', $data->branchId))
            ->exists();

        if ($exists) {
            throw new HolidayAlreadyExists;
        }

        return Holiday::query()->create([
            'branch_id' => $data->branchId,
            'holiday_date' => $data->holidayDate->toDateString(),
            'name' => $data->name,
            'name_bn' => $data->nameBn,
            'created_by_user_id' => $actor->userId,
        ]);
    }
}
