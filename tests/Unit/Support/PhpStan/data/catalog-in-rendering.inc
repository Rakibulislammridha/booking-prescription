<?php

declare(strict_types=1);

namespace App\Domain\Prescription\Render;

use App\Models\Catalog\Generic;
use Illuminate\Support\Facades\DB;

final class CatalogInRenderingFixture
{
    public function byImportedType(Generic $generic): int
    {
        return (int) $generic->getKey();
    }

    public function byNewExpression(): object
    {
        return new \App\Models\Catalog\Brand;
    }

    public function byStaticCall(): mixed
    {
        return \App\Models\Catalog\Strength::query()->first();
    }

    public function byClassConstant(): string
    {
        return \App\Models\Catalog\DosageForm::class;
    }

    public function byConnection(): mixed
    {
        return DB::connection('catalog')->table('generics')->first();
    }

    public function byConfigKey(): mixed
    {
        return config('database.connections.catalog.database');
    }

    /** @param  array<string, mixed>  $snapshot */
    public function snapshotOnlyIsFine(array $snapshot): string
    {
        return (string) ($snapshot['brand_name'] ?? '');
    }
}
