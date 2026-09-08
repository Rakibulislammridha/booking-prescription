<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Scratch;

use Illuminate\Support\Facades\DB;

final class CatalogQueryBuilderWritesFixture
{
    public function directChain(): int
    {
        return DB::connection('catalog')->table('brands')->where('id', 1)->update(['name' => 'x']);
    }

    public function insertOnCatalog(): bool
    {
        return DB::connection('catalog')->table('generics')->insert(['name' => 'x']);
    }

    public function deleteOnCatalog(): int
    {
        return DB::connection('catalog')->table('generics')->where('id', 1)->delete();
    }

    public function throughAnAlias(): int
    {
        $catalog = DB::connection('catalog');

        return $catalog->table('strengths')->where('id', 1)->increment('id');
    }

    public function connectionLevelUpdate(): int
    {
        return DB::connection('catalog')->update('update generics set name = ?', ['x']);
    }

    public function adminConnectionIsTheSanctionedPath(): bool
    {
        return DB::connection('catalog_admin')->table('generics')->insert(['name' => 'x']);
    }

    public function readsAreFine(): mixed
    {
        return DB::connection('catalog')->table('generics')->where('is_active', true)->first();
    }

    public function otherConnectionsAreFine(): int
    {
        return DB::connection('pgsql')->table('patients')->where('id', 1)->update(['name' => 'x']);
    }

    public function anAmbiguousAliasIsLeftAlone(bool $admin): int
    {
        $connection = DB::connection('catalog');

        if ($admin) {
            $connection = DB::connection('catalog_admin');
        }

        return $connection->table('generics')->where('id', 1)->update(['name' => 'x']);
    }

    public function aNonLiteralConnectionIsLeftAlone(string $name): int
    {
        return DB::connection($name)->table('generics')->where('id', 1)->update(['name' => 'x']);
    }
}
