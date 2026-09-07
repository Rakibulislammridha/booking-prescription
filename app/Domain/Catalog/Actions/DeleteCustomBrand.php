<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Actions;

use App\Domain\Shared\Actor;
use App\Models\Tenant\CustomBrand;

/** Soft delete (SCHEMA §3.4): prescriptions keep their snapshot; the search document is removed by the Scout observer. */
final class DeleteCustomBrand
{
    public function handle(CustomBrand $brand, Actor $actor): void
    {
        $brand->delete();
    }
}
