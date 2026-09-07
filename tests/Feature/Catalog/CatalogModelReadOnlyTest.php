<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Domain\Catalog\Exceptions\CatalogIsReadOnly;
use App\Domain\Catalog\Services\CatalogWriteContext;
use App\Models\Catalog\Generic;
use App\Models\Catalog\Route;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * C2 in code: every CatalogModel write outside CatalogWriteContext::run() throws CatalogIsReadOnly; inside it the
 * model switches to catalog_admin. catalog_admin is transacted here so the writes roll back with the test.
 */
#[Group('catalog')]
final class CatalogModelReadOnlyTest extends TestCase
{
    /** @var array<int, string> */
    protected array $connectionsToTransact = ['pgsql', 'catalog', 'catalog_admin'];

    public function test_reads_use_the_runtime_connection(): void
    {
        $generic = Generic::query()->where('slug', 'paracetamol')->firstOrFail();

        $this->assertSame('catalog', $generic->getConnectionName());
        $this->assertFalse(app(CatalogWriteContext::class)->isOpen());
    }

    public function test_create_save_update_delete_outside_context_throw(): void
    {
        $generic = Generic::query()->where('slug', 'paracetamol')->firstOrFail();

        $this->assertThrows(fn () => Generic::query()->create(['name' => 'Testium', 'slug' => 'testium']), CatalogIsReadOnly::class);
        $this->assertThrows(fn () => $generic->update(['name' => 'Renamed']), CatalogIsReadOnly::class);
        $this->assertThrows(fn () => tap($generic->replicate(), fn ($g) => $g->slug = 'para-copy')->save(), CatalogIsReadOnly::class);
        $this->assertThrows(fn () => $generic->delete(), CatalogIsReadOnly::class);

        $this->assertSame('Paracetamol', Generic::query()->where('slug', 'paracetamol')->value('name'));
        $this->assertNull(Generic::query()->where('slug', 'testium')->first());

        try {
            $generic->update(['name' => 'Renamed']);
        } catch (CatalogIsReadOnly $e) {
            $this->assertSame('catalog.read_only', $e->code());
            $this->assertSame(Generic::class, $e->model);
        }
    }

    public function test_writes_inside_context_use_catalog_admin(): void
    {
        $context = app(CatalogWriteContext::class);

        $created = $context->run(function () use ($context): Generic {
            $this->assertTrue($context->isOpen());
            $g = Generic::query()->create(['name' => 'Testium', 'slug' => 'testium', 'aliases' => []]);
            $this->assertSame('catalog_admin', $g->getConnectionName());
            $g->update(['name' => 'Testium Renamed']);
            Route::query()->where('code', 'po')->firstOrFail()->update(['abbreviation' => 'PO']);

            return $g;
        });

        $this->assertFalse($context->isOpen());
        $this->assertSame('Testium Renamed', DB::connection('catalog_admin')->table('generics')->where('slug', 'testium')->value('name'));
        $this->assertSame('catalog', $created->fresh()?->getConnectionName() ?? Generic::query()->getModel()->getConnectionName());

        // Nested run() keeps the depth balanced and rolls back on exceptions.
        $this->assertThrows(fn () => $context->run(function () use ($context): void {
            $context->run(fn () => Generic::query()->create(['name' => 'Inner', 'slug' => 'inner', 'aliases' => []]));
            throw new \RuntimeException('boom');
        }), \RuntimeException::class);
        $this->assertFalse($context->isOpen());
        $this->assertNull(DB::connection('catalog_admin')->table('generics')->where('slug', 'inner')->first());
    }

    public function test_singleton_is_recreated_after_flush(): void
    {
        $first = app(CatalogWriteContext::class);
        app()->forgetInstance(CatalogWriteContext::class);                    // what Octane's 'flush' does between requests
        $second = app(CatalogWriteContext::class);

        $this->assertNotSame($first, $second);
        $this->assertFalse($second->isOpen());
        $this->assertSame($second, app(CatalogWriteContext::class));
    }
}
