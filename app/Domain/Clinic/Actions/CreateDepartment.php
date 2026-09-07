<?php

declare(strict_types=1);

namespace App\Domain\Clinic\Actions;

use App\Domain\Clinic\Data\DepartmentData;
use App\Domain\Shared\Actor;
use App\Models\Tenant\Department;

final class CreateDepartment
{
    public function handle(DepartmentData $data, Actor $actor): Department
    {
        return Department::query()->create($data->toAttributes());
    }
}
