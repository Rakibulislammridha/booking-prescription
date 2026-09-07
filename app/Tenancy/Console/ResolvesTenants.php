<?php

declare(strict_types=1);

namespace App\Tenancy\Console;

use App\Models\Central\Tenant;
use App\Tenancy\Exceptions\TenantNotFound;
use Illuminate\Support\Collection;

trait ResolvesTenants
{
    /**
     * --tenant=* accepts ids or slugs; with none given, every servable (trial|active|past_due) tenant is returned.
     *
     * @return Collection<int, Tenant>
     */
    protected function resolveTenants(bool $includeAllStatuses = false): Collection
    {
        /** @var array<int, string> $selectors */
        $selectors = array_filter((array) $this->option('tenant'), fn ($v) => $v !== null && $v !== '');

        if ($selectors === []) {
            $query = $includeAllStatuses ? Tenant::query() : Tenant::query()->active();

            return $query->orderBy('id')->get();
        }

        return collect($selectors)->map(function (string $selector): Tenant {
            $tenant = ctype_digit($selector)
                ? Tenant::query()->find((int) $selector)
                : Tenant::query()->where('slug', $selector)->first();

            return $tenant ?? throw new TenantNotFound($selector);
        })->values();
    }
}
