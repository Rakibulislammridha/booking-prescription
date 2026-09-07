<?php

declare(strict_types=1);

namespace App\Domain\Clinic\Actions;

use App\Domain\Shared\Actor;
use App\Models\Tenant\Department;

/** doctors.department_id is ON DELETE SET NULL (SCHEMA §3.1): a removed department never removes a doctor. */
final class DeleteDepartment
{
    public function handle(Department $department, Actor $actor): void
    {
        $department->delete();
    }
}
