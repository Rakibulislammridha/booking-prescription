<?php

declare(strict_types=1);

namespace Tests\Unit\Support\PhpStan;

use App\Support\PhpStan\NoCatalogQueryBuilderWrites;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use PHPUnit\Framework\Attributes\WithoutErrorHandler;

/**
 * ARCHITECTURE §5.1 / CATALOG.md §1.2 — the `catalog` connection is SELECT-only; writes go through
 * CatalogWriteContext on `catalog_admin`.
 *
 * The fixture also pins what the rule must stay quiet about: the sanctioned `catalog_admin` path, reads, other
 * connections, an alias that is not unambiguously the catalog connection, and a non-literal connection name.
 *
 * @extends RuleTestCase<NoCatalogQueryBuilderWrites>
 */
final class NoCatalogQueryBuilderWritesTest extends RuleTestCase
{
    private const TIP = 'CATALOG.md §1.3: write through CatalogWriteContext::run(), which opens a transaction on the catalog_admin connection (catalog:migrate / catalog:seed / catalog:import / PromoteCustomBrand). The catalog connection is granted SELECT only.';

    #[WithoutErrorHandler]
    public function test_it_reports_query_builder_writes_aimed_at_the_read_only_catalog_connection(): void
    {
        $this->analyse([__DIR__.'/data/catalog-query-builder-writes.inc'], [
            ["The 'catalog' connection is read-only: update() on a query builder built from it is a write that bypasses CatalogWriteContext.", 13, self::TIP],
            ["The 'catalog' connection is read-only: insert() on a query builder built from it is a write that bypasses CatalogWriteContext.", 18, self::TIP],
            ["The 'catalog' connection is read-only: delete() on a query builder built from it is a write that bypasses CatalogWriteContext.", 23, self::TIP],
            ["The 'catalog' connection is read-only: increment() on a query builder built from it is a write that bypasses CatalogWriteContext.", 30, self::TIP],
            ["The 'catalog' connection is read-only: update() on a query builder built from it is a write that bypasses CatalogWriteContext.", 35, self::TIP],
        ]);
    }

    #[WithoutErrorHandler]
    public function test_it_skips_the_tests_namespace_so_the_adversarial_guard_test_can_perform_the_write(): void
    {
        $this->analyse([__DIR__.'/data/catalog-query-builder-writes-in-tests.inc'], []);
    }

    protected function getRule(): Rule
    {
        return new NoCatalogQueryBuilderWrites;
    }
}
