<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use Illuminate\Support\Facades\DB;

/** An adversarial test must be able to perform the write it asserts is refused: the rule skips the Tests\ namespace. */
final class CatalogQueryBuilderWritesInTestsFixture
{
    public function theGuardIsProvedByDoingIt(): int
    {
        return DB::connection('catalog')->table('routes')->where('code', 'nope')->update(['name' => 'x']);
    }
}
