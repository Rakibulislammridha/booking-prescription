<?php

declare(strict_types=1);

namespace App\Models\Tenant;

use App\Models\Tenant\Concerns\RequiresTenancy;
use Spatie\Permission\Models\Permission as SpatiePermission;

final class Permission extends SpatiePermission
{
    use RequiresTenancy;

    protected $connection = 'pgsql';
}
