<?php

declare(strict_types=1);

namespace App\Domain\Prescription\Services;

use App\Models\Catalog\Generic;
use Illuminate\Support\Facades\DB;

/** SnapshotBuilder runs at issue time and MAY read the catalog: the rule must not fire outside the Render namespace. */
final class CatalogOutsideRenderingFixture
{
    public function readsTheCatalog(Generic $generic): mixed
    {
        return DB::connection('catalog')->table('brands')->find($generic->getKey());
    }
}
