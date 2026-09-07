<?php

declare(strict_types=1);

namespace App\Models\Catalog;

use App\Domain\Catalog\Exceptions\CatalogIsReadOnly;
use App\Domain\Catalog\Services\CatalogWriteContext;
use Illuminate\Database\Eloquent\Model;

/**
 * Catalog-database models (CATALOG.md §1.2). SELECT-only at runtime: writes go through CatalogWriteContext::run()
 * on the catalog_admin connection (catalog:migrate, catalog:import, PromoteCustomBrand). NOT Scout-searchable.
 * CatalogWriteContext / CatalogIsReadOnly are provided by the Catalog module.
 */
abstract class CatalogModel extends Model
{
    public $timestamps = true;                       // written by the importer under catalog_admin; catalog_version_id marks the release

    protected $guarded = [];

    /** Only boot<Trait>() methods are called automatically: the base class must register its own guards. */
    protected static function boot(): void
    {
        parent::boot();
        static::bootCatalogModel();
    }

    public function getConnectionName(): ?string
    {
        return app(CatalogWriteContext::class)->isOpen() ? 'catalog_admin' : 'catalog';
    }

    public static function bootCatalogModel(): void
    {
        foreach (['creating', 'updating', 'deleting', 'saving'] as $event) {
            static::registerModelEvent($event, fn () => app(CatalogWriteContext::class)->isOpen() || throw new CatalogIsReadOnly(static::class));
        }
    }
}
