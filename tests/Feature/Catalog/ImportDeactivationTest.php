<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Domain\Catalog\Data\ImportRequest;
use App\Domain\Catalog\Enums\ImportSource;
use App\Domain\Catalog\Import\CatalogImporter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * --full deactivates what the bundle omits and never deletes; partial bundles deactivate nothing; DGDA-style rows
 * resolve generics, brands and strengths by canonical key and record issues for what cannot be mapped.
 */
#[Group('catalog')]
final class ImportDeactivationTest extends TestCase
{
    /** @var array<int, string> */
    protected array $connectionsToTransact = ['pgsql', 'catalog', 'catalog_admin'];

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = storage_path('framework/testing/catalog-import-'.getmypid());
        File::ensureDirectoryExists($this->dir);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    public function test_dgda_rows_upsert_by_canonical_key_and_record_issues(): void
    {
        $this->products(<<<'CSV'
        Manufacturer,Brand Name,Generic Name,Strength,Dosage Form,Pack Size,DAR No,Status
        Square Pharmaceuticals,Napa,Paracetamol BP,500 mg,Tablet,10x10,DAR-001007,active
        Square Pharmaceuticals,Napa,Paracetamol,1000 mg,Tablet,10x10,DAR-001007,active
        Testco,Testomax,Paracetamol,650 mg,Tablet,10x10,DAR-9,active
        Testco,Testomax,Paracetamol,650 mg,Hologram,10x10,DAR-9,active
        Testco,Testomax,Paracetamol,lots,Tablet,10x10,DAR-9,active
        Testco,Novelix,Novelium Hydrochloride,10 mg,Tab.,3x10,DAR-10,active
        Other Ltd,Testomax,Paracetamol,650 mg,Capsule,10x10,DAR-11,active
        CSV);

        $report = app(CatalogImporter::class)->run(new ImportRequest(path: $this->dir, source: ImportSource::Dgda, version: 'test.dgda'));
        $admin = DB::connection('catalog_admin');

        $this->assertTrue($report->isApplied());
        $this->assertSame(['duplicate_brand' => 1, 'unknown_form' => 1, 'unknown_generic' => 1, 'unparsable_strength' => 1], $report->issues);

        $napa = $admin->table('brands')->where('name', 'Napa')->first();
        $this->assertSame(1, $admin->table('brands')->whereRaw('lower(name) = ?', ['napa'])->count(), 'Napa upserted, not duplicated');
        $this->assertSame(990, (int) $napa->popularity, 'DGDA rows never overwrite popularity');
        $this->assertSame('DAR-001007', $napa->dar_number);
        $this->assertSame(7, $admin->table('strengths')->where('brand_id', $napa->id)->count(), '6 seed presentations + the new 1000 mg');
        $tab = (int) $admin->table('dosage_forms')->where('code', 'tab')->value('id');
        $this->assertSame(1, $admin->table('strengths')->where('brand_id', $napa->id)->where('dosage_form_id', $tab)->where('strength_label', '500 mg')->count(), 'existing key untouched');
        $this->assertSame(1, $admin->table('strengths')->where('brand_id', $napa->id)->where('dosage_form_id', $tab)->where('strength_label', '1000 mg')->count());

        $testomax = $admin->table('brands')->where('name', 'Testomax')->first();
        $this->assertSame('Testco', $testomax->manufacturer);
        $this->assertSame('testomax-paracetamol', $testomax->slug);
        $this->assertSame(1, $admin->table('strengths')->where('brand_id', $testomax->id)->count());

        $novelium = $admin->table('generics')->where('slug', 'novelium')->first();
        $this->assertTrue((bool) $novelium->needs_review);
        $this->assertSame(1, $admin->table('brands')->where('generic_id', $novelium->id)->count(), 'a brand is never imported without a generic');

        $kinds = $admin->table('catalog_import_issues')->where('catalog_version_id', $report->versionId)->pluck('payload', 'kind');
        $this->assertSame('Hologram', json_decode((string) $kinds['unknown_form'], true)['form_text']);
        $this->assertSame('lots', json_decode((string) $kinds['unparsable_strength'], true)['strength_label']);
        $this->assertSame('Testco', json_decode((string) $kinds['duplicate_brand'], true)['existing_manufacturer']);
    }

    public function test_full_import_deactivates_missing_rows_without_deleting(): void
    {
        $admin = DB::connection('catalog_admin');
        $strengthsBefore = $admin->table('strengths')->count();
        $brandsBefore = $admin->table('brands')->count();

        $this->products("Manufacturer,Brand Name,Generic Name,Strength,Dosage Form,Pack Size,DAR No,Status\nBeximco Pharmaceuticals,Napa,Paracetamol,500 mg,Tablet,10x10,DAR-1,active\n");

        $partial = app(CatalogImporter::class)->run(new ImportRequest(path: $this->dir, source: ImportSource::Dgda, version: 'test.partial'));
        $this->assertSame(0, $partial->rowCounts['brands']['deactivated']);
        $this->assertSame(0, $partial->rowCounts['strengths']['deactivated'] ?? 0);
        $this->assertSame($brandsBefore, $admin->table('brands')->where('is_active', true)->count() + 1, 'partial bundle deactivates nothing (Ranitid was already inactive)');

        $full = app(CatalogImporter::class)->run(new ImportRequest(path: $this->dir, source: ImportSource::Dgda, version: 'test.full', full: true, force: true));

        $this->assertSame($brandsBefore, $admin->table('brands')->count(), 'nothing deleted');
        $this->assertSame($strengthsBefore, $admin->table('strengths')->count(), 'nothing deleted');
        $this->assertSame($brandsBefore - 2, $full->rowCounts['brands']['deactivated'], 'every brand except Napa (and the already inactive Ranitid)');
        $this->assertSame(1, $admin->table('brands')->where('is_active', true)->count());
        $this->assertSame(1, $admin->table('strengths')->where('is_active', true)->count());

        $seclo = $admin->table('brands')->where('name', 'Seclo')->first();
        $this->assertFalse((bool) $seclo->is_active);
        $this->assertNotNull($seclo->discontinued_at);
        $this->assertSame($full->versionId, (int) $seclo->catalog_version_id);

        $napa = $admin->table('brands')->where('name', 'Napa')->first();
        $this->assertTrue((bool) $napa->is_active);
        $this->assertNull($napa->discontinued_at);
        $this->assertSame(75, $admin->table('generics')->where('is_active', true)->count(), 'generics untouched: the bundle has no generics.csv');
        $this->assertContains($seclo->id, $full->changedIds['brands']);
    }

    private function products(string $csv): void
    {
        File::put($this->dir.'/products.csv', preg_replace('/^\s+/m', '', $csv));
    }
}
