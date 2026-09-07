<?php

declare(strict_types=1);

namespace App\Domain\Clinic\Actions;

use App\Domain\Clinic\Data\DepartmentData;
use App\Domain\Shared\Actor;
use App\Models\Tenant\Department;

final class UpdateDepartment
{
    public function handle(Department $department, DepartmentData $data, Actor $actor): Department
    {
        $department->fill($data->toAttributes())->save();

        return $department;
    }
}
