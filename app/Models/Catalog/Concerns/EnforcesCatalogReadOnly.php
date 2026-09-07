<?php

declare(strict_types=1);

namespace App\Models\Catalog\Concerns;

/**
 * Laravel auto-invokes boot<Trait>() for traits only, and the foundation's CatalogModel::boot() does not call
 * bootCatalogModel() (unlike TenantModel::boot()), so the read-only hooks it defines were never registered. Every
 * catalog model uses this trait so the creating/updating/deleting/saving guards of CatalogModel run (CATALOG.md §1.2).
 * Becomes a harmless double registration once the base class calls bootCatalogModel() itself.
 */
trait EnforcesCatalogReadOnly
{
    public static function bootEnforcesCatalogReadOnly(): void
    {
        static::bootCatalogModel();
    }
}
