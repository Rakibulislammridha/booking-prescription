<?php

declare(strict_types=1);

namespace App\Models\Tenant;

use App\Models\Tenant\Concerns\RequiresTenancy;
use Spatie\Permission\Models\Role as SpatieRole;

final class Role extends SpatieRole
{
    use RequiresTenancy;

    protected $connection = 'pgsql';
}
