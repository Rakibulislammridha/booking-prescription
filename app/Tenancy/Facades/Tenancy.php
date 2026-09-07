<?php

declare(strict_types=1);

namespace App\Tenancy\Facades;

use App\Models\Central\Tenant;
use Closure;
use Illuminate\Support\Facades\Facade;

/**
 * @method static void initialize(Tenant $tenant)
 * @method static void end()
 * @method static mixed run(Tenant $tenant, Closure $callback)
 * @method static Tenant|null current()
 * @method static bool check()
 * @method static int|null id()
 * @method static string|null schema()
 *
 * @see \App\Tenancy\Tenancy
 */
final class Tenancy extends Facade
{
    /** Never cache the root: the singleton is in Octane's 'flush' list, and a cached root would outlive the flush. */
    protected static $cached = false;

    protected static function getFacadeAccessor(): string
    {
        return \App\Tenancy\Tenancy::class;
    }
}
