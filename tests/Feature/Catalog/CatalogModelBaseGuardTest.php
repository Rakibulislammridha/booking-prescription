<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Domain\Catalog\Exceptions\CatalogIsReadOnly;
use App\Domain\Catalog\Services\CatalogWriteContext;
use App\Models\Catalog\CatalogModel;
use App\Models\Catalog\Route;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Foundation guarantee: CatalogModel::boot() registers the read-only hooks itself (Eloquent only auto-invokes
 * boot<Trait>() methods), so a catalog model is refused outside CatalogWriteContext without opting into any concern
 * — and the non-production SELECT-only net on the `catalog` connection refuses a raw write too (CATALOG.md §1.3).
 */
final class CatalogModelBaseGuardTest extends TestCase
{
    public function test_a_catalog_model_without_the_concern_cannot_be_saved_outside_the_write_context(): void
    {
        $model = new class extends CatalogModel
        {
            protected $table = 'routes';
        };

        $model->forceFill(['code' => 'ZZ', 'name' => 'Adversarial', 'name_bn' => 'x']);

        $this->assertThrows(fn () => $model->save(), CatalogIsReadOnly::class);
        $this->assertFalse($model->exists);
        $this->assertFalse(app(CatalogWriteContext::class)->isOpen());
    }

    public function test_a_shipped_catalog_model_cannot_be_created_updated_or_deleted_outside_the_write_context(): void
    {
        $route = Route::query()->firstOrFail();

        $this->assertThrows(fn () => Route::query()->create(['code' => 'po', 'name' => 'Adversarial']), CatalogIsReadOnly::class);
        $this->assertThrows(fn () => $route->update(['name' => 'Renamed']), CatalogIsReadOnly::class);
        $this->assertThrows(fn () => $route->delete(), CatalogIsReadOnly::class);
        $this->assertNotSame('Renamed', $route->fresh()?->name);
    }

    public function test_the_catalog_connection_refuses_raw_writes_outside_the_write_context(): void
    {
        $this->assertGreaterThanOrEqual(0, DB::connection('catalog')->table('routes')->count());   // reads pass the net

        $this->assertThrows(fn () => DB::connection('catalog')->table('routes')->where('code', 'nope')->update(['name' => 'x']), CatalogIsReadOnly::class);
        $this->assertThrows(fn () => DB::connection('catalog')->statement('delete from routes where code = ?', ['nope']), CatalogIsReadOnly::class);
    }
}
