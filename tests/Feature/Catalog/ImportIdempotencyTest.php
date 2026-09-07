<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Domain\Catalog\Data\ImportRequest;
use App\Domain\Catalog\Enums\CatalogVersionStatus;
use App\Domain\Catalog\Enums\ImportSource;
use App\Domain\Catalog\Import\CatalogImporter;
use App\Domain\Catalog\Services\CatalogCache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Importing the seed bundle again yields zero changes (C3). catalog_admin is transacted so the versions created here
 * roll back with the test; every read goes through catalog_admin for that reason.
 */
#[Group('catalog')]
final class ImportIdempotencyTest extends TestCase
{
    /** @var array<int, string> */
    protected array $connectionsToTransact = ['pgsql', 'catalog', 'catalog_admin'];

    public function test_same_bundle_twice_changes_nothing(): void
    {
        $admin = DB::connection('catalog_admin');
        $before = $this->snapshot();
        $appliedBefore = $admin->table('catalog_versions')->where('status', 'applied')->value('id');

        $report = app(CatalogImporter::class)->run(new ImportRequest(path: (string) config('catalog.seed_path'), source: ImportSource::Seed, version: 'test.'.Str::lower((string) Str::ulid()), force: true));

        $this->assertTrue($report->isApplied());
        $this->assertSame(0, $report->totalChanges(), json_encode($report->rowCounts));
        $this->assertSame([], $report->issues);
        $this->assertSame($before, $this->snapshot(), 'no row changed updated_at or catalog_version_id');

        $version = (array) $admin->table('catalog_versions')->find($report->versionId);
        $this->assertSame(CatalogVersionStatus::Applied->value, $version['status']);
        $this->assertSame(CatalogVersionStatus::Superseded->value, $admin->table('catalog_versions')->where('id', $appliedBefore)->value('status'));

        foreach (json_decode((string) $version['row_counts'], true) as $table => $counts) {
            $this->assertSame(0, $counts['inserted'] + $counts['updated'] + $counts['deactivated'], "{$table} delta");
        }

        $this->assertSame(0, $admin->table('strengths')->where('catalog_version_id', $report->versionId)->count());
    }

    public function test_already_imported_guard_and_dry_run(): void
    {
        $importer = app(CatalogImporter::class);
        $admin = DB::connection('catalog_admin');
        $versions = $admin->table('catalog_versions')->count();

        $again = $importer->run(new ImportRequest(path: (string) config('catalog.seed_path'), source: ImportSource::Seed));
        $this->assertSame('already_imported', $again->status);
        $this->assertSame($versions, $admin->table('catalog_versions')->count());

        $dry = $importer->run(new ImportRequest(path: (string) config('catalog.seed_path'), source: ImportSource::Seed, version: 'test.dry', force: true, dryRun: true));
        $this->assertSame('dry_run', $dry->status);
        $this->assertSame(75, $dry->rowCounts['generics']['rows']);
        $this->assertSame($versions, $admin->table('catalog_versions')->count(), 'dry run rolled back');
        $this->assertNull($admin->table('catalog_versions')->where('version', 'test.dry')->first());
    }

    public function test_import_bumps_the_cache_version_prefix(): void
    {
        $cache = app(CatalogCache::class);
        $cache->generic(1);
        $before = $cache->currentVersion();

        app(CatalogImporter::class)->run(new ImportRequest(path: (string) config('catalog.seed_path'), source: ImportSource::Seed, version: 'test.bump', force: true));

        // bumpVersion() forgets the pointer: the next lookup re-reads the applied row (through the runtime connection).
        $this->assertNull(app('cache')->get('catalog:current_version'));
        $this->assertStringStartsWith('dev.', $before);
    }

    /** @return array<string, string> table → sha of (id, updated_at, catalog_version_id) */
    private function snapshot(): array
    {
        $out = [];

        foreach (['generics', 'brands', 'strengths', 'icd10_codes', 'drug_interactions', 'allergy_classes', 'pregnancy_categories', 'renal_cautions', 'hepatic_cautions', 'max_daily_doses', 'drug_information', 'dosage_forms', 'routes'] as $table) {
            $rows = DB::connection('catalog_admin')->table($table)->orderBy('id')->get(['id', 'updated_at', 'catalog_version_id', 'is_active']);
            $out[$table] = sha1($rows->toJson());
        }

        $rows = DB::connection('catalog_admin')->table('allergy_class_generics')->orderBy('id')->get(['id', 'created_at', 'catalog_version_id']);
        $out['allergy_class_generics'] = sha1($rows->toJson());

        return $out;
    }
}
