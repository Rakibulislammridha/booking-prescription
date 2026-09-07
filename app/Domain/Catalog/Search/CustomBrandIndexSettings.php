<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Search;

use App\Models\Tenant\CustomBrand;
use App\Tenancy\Facades\Tenancy;

/**
 * Settings of t{tenant_id}_custom_brands (CATALOG.md §4.2). register() publishes them under
 * scout.meilisearch.index-settings.<uid> so scout:sync-index-settings (run per tenant by tenants:sync-search-settings)
 * applies exactly what MeilisearchIndexes::ensureTenantIndexes() applies at provisioning.
 */
final class CustomBrandIndexSettings
{
    /** @return array<string, mixed> */
    public static function array(): array
    {
        return (array) config('catalog.search.custom_brands');
    }

    /** Requires an active tenant (the uid carries the tenant id). */
    public static function register(): void
    {
        if (! Tenancy::check()) {
            return;
        }

        config(['scout.meilisearch.index-settings.'.(new CustomBrand)->searchableAs() => self::array()]);
    }
}
